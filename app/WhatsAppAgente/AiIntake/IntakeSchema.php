<?php
namespace App\WhatsAppAgente\AiIntake;

/**
 * IntakeSchema — fonte de verdade do Structured Output do agente de pre-atendimento.
 *
 * O MESMO schema vale em producao (response_format json_schema strict da OpenAI) e nos
 * testes. Compativel com as regras de strict mode da OpenAI: additionalProperties:false em
 * todo objeto, TODOS os campos em required (opcional = uniao com null), sem min/max/pattern
 * (esses constraints sao validados aqui no backend, nao no schema enviado ao modelo).
 *
 * Estrutura conforme a secao 23 do prompt mestre.
 */
final class IntakeSchema
{
    public const INTENTS  = ['office_information','new_intake','existing_case','human_request','document_submission','non_legal','unknown'];
    public const URGENCY  = ['normal','high','critical'];

    /** Schema JSON (para response_format e validacao). */
    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'intent','primary_practice_area','secondary_practice_areas','classification_confidence',
                'answer_relevant_to_current_question','extracted_data','urgency_level','urgency_reasons',
                'risk_flags','missing_essential_fields','suggested_next_question_key','enough_for_handoff',
                'should_handoff_immediately','should_stop_bot','summary',
            ],
            'properties' => [
                'intent' => ['type' => 'string', 'enum' => self::INTENTS],
                'primary_practice_area' => ['type' => ['string','null']],
                'secondary_practice_areas' => ['type' => 'array', 'items' => ['type' => 'string']],
                'classification_confidence' => ['type' => 'number'],
                'answer_relevant_to_current_question' => ['type' => 'boolean'],
                'extracted_data' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['name','city','state','main_report','event_date','has_existing_case',
                        'case_number','has_deadline','deadline_description','has_hearing','hearing_date',
                        'has_official_notice','has_documents','mentioned_documents'],
                    'properties' => [
                        'name' => ['type' => ['string','null']],
                        'city' => ['type' => ['string','null']],
                        'state' => ['type' => ['string','null']],
                        'main_report' => ['type' => ['string','null']],
                        'event_date' => ['type' => ['string','null']],
                        'has_existing_case' => ['type' => ['boolean','null']],
                        'case_number' => ['type' => ['string','null']],
                        'has_deadline' => ['type' => ['boolean','null']],
                        'deadline_description' => ['type' => ['string','null']],
                        'has_hearing' => ['type' => ['boolean','null']],
                        'hearing_date' => ['type' => ['string','null']],
                        'has_official_notice' => ['type' => ['boolean','null']],
                        'has_documents' => ['type' => ['boolean','null']],
                        'mentioned_documents' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'urgency_level' => ['type' => 'string', 'enum' => self::URGENCY],
                'urgency_reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
                'risk_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'missing_essential_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                'suggested_next_question_key' => ['type' => ['string','null']],
                'enough_for_handoff' => ['type' => 'boolean'],
                'should_handoff_immediately' => ['type' => 'boolean'],
                'should_stop_bot' => ['type' => 'boolean'],
                'summary' => ['type' => 'string'],
            ],
        ];
    }

    /** Envelope response_format para a OpenAI (Chat Completions, strict). */
    public static function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name'   => 'pre_atendimento',
                'strict' => true,
                'schema' => self::jsonSchema(),
            ],
        ];
    }

    /** Objeto default valido (fallback + base para o FakeProvider). */
    public static function defaults(): array
    {
        return [
            'intent' => 'unknown',
            'primary_practice_area' => null,
            'secondary_practice_areas' => [],
            'classification_confidence' => 0.0,
            'answer_relevant_to_current_question' => true,
            'extracted_data' => [
                'name' => null, 'city' => null, 'state' => null, 'main_report' => null,
                'event_date' => null, 'has_existing_case' => null, 'case_number' => null,
                'has_deadline' => null, 'deadline_description' => null, 'has_hearing' => null,
                'hearing_date' => null, 'has_official_notice' => null, 'has_documents' => null,
                'mentioned_documents' => [],
            ],
            'urgency_level' => 'normal',
            'urgency_reasons' => [],
            'risk_flags' => [],
            'missing_essential_fields' => [],
            'suggested_next_question_key' => null,
            'enough_for_handoff' => false,
            'should_handoff_immediately' => false,
            'should_stop_bot' => false,
            'summary' => '',
        ];
    }

    /**
     * Valida um objeto contra o schema canonico. Retorna lista de erros (vazia = valido).
     * Subset de JSON Schema: type (com uniao), enum, required, additionalProperties:false, items.
     */
    public static function validate($obj, ?array $schema = null, string $path = '$'): array
    {
        $schema = $schema ?? self::jsonSchema();
        $errors = [];
        $type = $schema['type'] ?? null;
        if ($type !== null && !self::typeOk($obj, $type)) {
            return ["$path: tipo invalido (esperado " . json_encode($type) . ")"];
        }
        if (is_array($obj) && self::isAssoc($obj) || (is_array($obj) && ($schema['type'] ?? '') === 'object')) {
            // tratado abaixo como objeto
        }
        if (($schema['type'] ?? '') === 'object') {
            $props = $schema['properties'] ?? [];
            foreach (($schema['required'] ?? []) as $req) {
                if (!array_key_exists($req, (array)$obj)) {
                    $errors[] = "$path: campo obrigatorio ausente '$req'";
                }
            }
            if (($schema['additionalProperties'] ?? true) === false) {
                foreach ((array)$obj as $k => $_) {
                    if (!isset($props[$k])) $errors[] = "$path: propriedade nao permitida '$k'";
                }
            }
            foreach ($props as $k => $sub) {
                if (array_key_exists($k, (array)$obj)) {
                    $errors = array_merge($errors, self::validate(((array)$obj)[$k], $sub, "$path.$k"));
                }
            }
        } elseif (($schema['type'] ?? '') === 'array') {
            if (isset($schema['items'])) {
                foreach ((array)$obj as $i => $item) {
                    $errors = array_merge($errors, self::validate($item, $schema['items'], "$path[$i]"));
                }
            }
        } elseif (is_string($obj) && isset($schema['enum']) && !in_array($obj, $schema['enum'], true)) {
            $errors[] = "$path: valor '$obj' fora do enum";
        }
        return $errors;
    }

    /** Coerce: completa campos faltantes com defaults (defensivo contra saida parcial). */
    public static function coerce($obj): array
    {
        $d = self::defaults();
        if (!is_array($obj)) return $d;
        $out = array_merge($d, $obj);
        $out['extracted_data'] = array_merge($d['extracted_data'], is_array($obj['extracted_data'] ?? null) ? $obj['extracted_data'] : []);
        foreach (['secondary_practice_areas','urgency_reasons','risk_flags','missing_essential_fields'] as $arr) {
            if (!is_array($out[$arr] ?? null)) $out[$arr] = [];
        }
        if (!is_array($out['extracted_data']['mentioned_documents'] ?? null)) {
            $out['extracted_data']['mentioned_documents'] = [];
        }
        return $out;
    }

    private static function typeOk($value, $typeSpec): bool
    {
        $types = is_array($typeSpec) ? $typeSpec : [$typeSpec];
        foreach ($types as $t) {
            switch ($t) {
                case 'object':  if (is_array($value)) return true; break;
                case 'array':   if (is_array($value)) return true; break;
                case 'string':  if (is_string($value)) return true; break;
                case 'integer': if (is_int($value)) return true; break;
                case 'number':  if (is_int($value) || is_float($value)) return true; break;
                case 'boolean': if (is_bool($value)) return true; break;
                case 'null':    if ($value === null) return true; break;
            }
        }
        return false;
    }

    private static function isAssoc(array $a): bool
    {
        return array_keys($a) !== range(0, count($a) - 1);
    }
}
