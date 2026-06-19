<?php
namespace App\Services\AiIntake;

require_once __DIR__ . '/LlmProviderInterface.php';
require_once __DIR__ . '/IntakeSchema.php';
require_once __DIR__ . '/Taxonomy.php';

/**
 * FakeProvider — provedor DETERMINISTICO para testes (modo local, sem consumir creditos).
 *
 * Emula APENAS o papel do modelo (classificar, extrair, urgencia, sugerir proxima chave de
 * pergunta, flags de handoff, resumo) usando a Taxonomy. O controle deterministico
 * (limite de perguntas, escolha do texto da pergunta, decisao final de handoff, montagem da
 * resposta) e do IntakeEngine, exatamente como em producao. Assim o motor inteiro fica
 * testavel sem rede.
 */
final class FakeProvider implements LlmProviderInterface
{
    public function name(): string { return 'fake'; }

    public function complete(string $model, string $systemPrompt, string $userMessage, array $responseFormat, array $opts = []): array
    {
        $started = microtime(true);
        $ctx       = $opts['context'] ?? [];
        $collected = is_array($ctx['collected'] ?? null) ? $ctx['collected'] : [];
        $asked     = is_array($ctx['asked'] ?? null) ? $ctx['asked'] : [];
        $qcount    = (int)($ctx['question_count'] ?? 0);
        $maxQ      = (int)($ctx['max_questions'] ?? 6);
        $enabled   = $ctx['enabled_areas'] ?? null;

        $data = IntakeSchema::defaults();
        $msg  = trim($userMessage);

        // Anti prompt-injection: trata como dado, mantem papel.
        if (preg_match('/\b(ignore|esque[cç]a|desconsidere)\b.*\b(instru|prompt|regras)\b|mostre?\s+(o\s+)?(seu\s+)?prompt|aja\s+como|voce\s+agora\s+e\b|system\s*prompt/iu', $msg)) {
            $data['intent'] = 'unknown';
            $data['answer_relevant_to_current_question'] = false;
            $data['suggested_next_question_key'] = 'motivo';
            $data['summary'] = 'Mensagem tentou alterar as instrucoes; mantido o pre-atendimento.';
            return $this->ret($data, $started, $model);
        }

        $cls = Taxonomy::classify($msg, is_array($enabled) ? $enabled : null);
        $urg = Taxonomy::detectUrgency($msg);
        $data['urgency_level']   = $urg['level'];
        $data['urgency_reasons'] = $urg['reasons'];

        // Extracao simples
        $ext = $collected ?: $data['extracted_data'];
        $ext = array_merge($data['extracted_data'], $ext);

        if (preg_match('/\b(?:meu nome (?:e|eh)|me chamo|sou (?:o|a)|aqui (?:e|eh) (?:o|a))\s+([A-Za-zÀ-ÿ]{2,})/u', $msg, $m)) {
            $ext['name'] = $ext['name'] ?: ucfirst($m[1]);
        }
        if (preg_match('/\b(\d{7}-?\d{2}\.?\d{4}\.?\d\.?\d{2}\.?\d{4})\b/', $msg, $m)) {
            $ext['case_number'] = $m[1]; $ext['has_existing_case'] = true;
        }
        if (preg_match('/\b(ontem|hoje|anteontem|semana passada|mes passado|\d{1,2}\/\d{1,2}(?:\/\d{2,4})?)\b/u', Taxonomy::normalize($msg), $m)) {
            $ext['event_date'] = $ext['event_date'] ?: $m[1];
        }
        $docs = [];
        foreach (['documento','contrato','print','foto','comprovante','nota fiscal','laudo','recibo','holerite','carteira','exame'] as $d) {
            if (str_contains(Taxonomy::normalize($msg), $d)) $docs[] = $d;
        }
        if ($docs) { $ext['has_documents'] = true; $ext['mentioned_documents'] = array_values(array_unique(array_merge($ext['mentioned_documents'] ?? [], $docs))); }
        if (str_contains(Taxonomy::normalize($msg), 'prazo') || str_contains(Taxonomy::normalize($msg), 'intima')) $ext['has_deadline'] = true;
        if (str_contains(Taxonomy::normalize($msg), 'audiencia')) $ext['has_hearing'] = true;

        // main_report: primeira mensagem substantiva
        $isGreeting = preg_match('/^\s*(oi+|ola|ol[aá]|bom dia|boa tarde|boa noite|e ai|opa)\b[\s!,.]*$/iu', $msg);
        if (!$isGreeting && mb_strlen($msg) >= 12 && empty($ext['main_report'])) {
            $ext['main_report'] = mb_substr($msg, 0, 800);
        }
        $data['extracted_data'] = $ext;

        // Intencao
        $norm = Taxonomy::normalize($msg);
        $human    = (bool)preg_match('/\b(falar com|quero|chamar)\b.*\b(atendente|humano|pessoa|advogad|dr|dra)\b|atendimento humano/u', $norm);
        $existing = (bool)preg_match('/\b(ja sou|sou)\b.*\bcliente\b|meu processo|numero do processo/u', $norm);
        $office   = (bool)preg_match('/\b(voces|vcs)\b.*(fazem|atendem|trabalham)|onde (fica|ficam)|horario|endereco|telefone de voces|quanto custa|valor da consulta/u', $norm);
        $spam     = (bool)preg_match('/promocao|ganhe dinheiro|clique aqui|bit\.ly|https?:\/\/|investimento garantido|renda extra/u', $norm);

        if ($spam)          $data['intent'] = 'non_legal';
        elseif ($office)    $data['intent'] = 'office_information';
        elseif ($human)     $data['intent'] = 'human_request';
        elseif ($existing)  $data['intent'] = 'existing_case';
        elseif ($cls['area'] || !empty($ext['main_report'])) $data['intent'] = 'new_intake';
        else                $data['intent'] = 'unknown';

        $data['primary_practice_area']     = $cls['area'];
        $data['secondary_practice_areas']  = $cls['secondary'];
        $data['classification_confidence'] = $cls['confidence'];

        // Campos essenciais que faltam
        $missing = [];
        if (empty($ext['main_report'])) $missing[] = 'main_report';
        if (empty($data['primary_practice_area']) && !$office && !$spam) $missing[] = 'primary_practice_area';
        $data['missing_essential_fields'] = $missing;

        // Proxima chave de pergunta (so as ainda nao feitas)
        $bank = ['motivo','quando','processo','prazo','documentos','cidade'];
        $need = [];
        if (empty($ext['main_report']))        $need[] = 'motivo';
        if (empty($ext['event_date']))         $need[] = 'quando';
        if ($ext['has_existing_case'] === null) $need[] = 'processo';
        if ($ext['has_deadline'] === null)     $need[] = 'prazo';
        if ($ext['has_documents'] === null)    $need[] = 'documentos';
        if (empty($ext['city']))               $need[] = 'cidade';
        $next = null;
        foreach ($need as $k) { if (!in_array($k, $asked, true)) { $next = $k; break; } }
        $data['suggested_next_question_key'] = $next;

        // Flags de handoff (advisory; engine decide o efetivo)
        $critical = $data['urgency_level'] === 'critical';
        $enough = $critical || $human || $existing
            || (!empty($ext['main_report']) && (!empty($data['primary_practice_area']) || ($qcount + 1) >= $maxQ))
            || (($qcount + 1) >= $maxQ);
        $data['enough_for_handoff'] = (bool)$enough;
        $data['should_handoff_immediately'] = (bool)($critical || $human || $existing);
        $data['should_stop_bot'] = (bool)($data['should_handoff_immediately'] || $spam || ($enough && $data['intent'] === 'new_intake'));

        // Resumo curto
        $areaName = $cls['name'] ?: ($data['primary_practice_area'] ?: 'a definir');
        $rep = $ext['main_report'] ? mb_substr($ext['main_report'], 0, 160) : '';
        $data['summary'] = trim("Lead ($areaName). " . $rep . " Urgencia: {$data['urgency_level']}.");

        return $this->ret($data, $started, $model);
    }

    private function ret(array $data, float $started, string $model): array
    {
        return [
            'ok' => true, 'refused' => false, 'reason' => null, 'data' => $data,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
            'error' => null, 'model' => $model ?: 'fake',
        ];
    }
}
