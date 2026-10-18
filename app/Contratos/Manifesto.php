<?php
namespace App\Contratos;

/**
 * O manifesto de evidências: um JSON com os identificadores, os hashes de cada
 * arquivo, cada assinatura e o último elo da trilha de eventos, SELADO pela
 * plataforma (Ed25519, chave fora do banco, identificada por chave_id).
 *
 * O selo prova que aquele conteúdo saiu desta plataforma e não mudou desde então.
 * Ele NÃO é a assinatura de nenhum participante: a manifestação de vontade de
 * cada um está no aceite e na confirmação por código, registrados em cada
 * assinatura. O hash do PDF de conclusão fica aqui, fora do próprio PDF (não dá
 * para um arquivo conter o próprio hash).
 *
 * Formato: {"conteudo": {...}, "selo": {"algoritmo","chave_id","assinatura"}},
 * e a assinatura é sobre Cripto::canonico(conteudo).
 */
final class Manifesto
{
    public const FORMATO = 'yuris-contratos-manifesto/1';

    public static function montar(array $contrato, array $versao, array $assinaturas, array $cadeia, array $arquivos, string $empresa): array
    {
        $conteudo = [
            'formato' => self::FORMATO,
            'empresa' => $empresa,
            'contrato' => ['uuid' => $contrato['uuid'], 'codigo_verificacao' => $contrato['codigo_verificacao'], 'titulo' => $contrato['titulo'],
                           'concluido_em_utc' => Tempo::paraIso($contrato['concluido_em'])],
            'versao' => ['uuid' => $versao['uuid'], 'numero' => (int) $versao['numero'], 'sha256_original' => $versao['sha256']],
            'assinaturas' => array_map(static function ($a) {
                $ev = json_decode((string) $a['evidencia_json'], true);
                return [
                    'assinatura_uuid' => $a['uuid'], 'participante_uuid' => $ev['participante']['uuid'], 'papel' => $ev['participante']['papel'],
                    'nome' => $ev['signatario']['nome'], 'cpf_mascara' => $ev['signatario']['cpf_mascara'], 'origem_identificacao' => $ev['signatario']['origem'],
                    'instante_utc' => $ev['instante_utc'], 'metodo' => $ev['confirmacao']['metodo'], 'contato' => $ev['confirmacao']['contato'],
                    'evidencia_sha256' => $a['evidencia_sha256'],
                ];
            }, $assinaturas),
            'trilha' => ['eventos' => $cadeia['quantidade'], 'hash_final' => $cadeia['hash_final'], 'integra_na_geracao' => $cadeia['integra']],
            'arquivos' => $arquivos,
            'horario' => 'Horários registrados pelo relógio do servidor, em UTC. Não há carimbo do tempo de terceiro.',
            'tipo_assinatura' => 'Assinatura eletrônica com confirmação por código de uso único enviado ao WhatsApp. Não é assinatura eletrônica qualificada (ICP-Brasil).',
            'gerado_em_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        return ['conteudo' => $conteudo, 'selo' => Cripto::selar(Cripto::canonico($conteudo))];
    }

    /** @return array{valido:bool, motivo:?string} confere o selo com a chave pública da plataforma. */
    public static function conferir(array $manifesto, ?array $chave = null): array
    {
        $chave = $chave ?? Cripto::chavePublica();
        if (!$chave) return ['valido' => false, 'motivo' => 'Chave do selo não configurada neste servidor.'];
        $selo = $manifesto['selo'] ?? null;
        if (!is_array($selo) || !isset($manifesto['conteudo'])) return ['valido' => false, 'motivo' => 'Manifesto sem selo.'];
        if (($selo['chave_id'] ?? '') !== $chave['chave_id']) return ['valido' => false, 'motivo' => 'Selo feito com outra chave (' . ($selo['chave_id'] ?? '?') . ').'];
        $ok = Cripto::conferirSelo(Cripto::canonico($manifesto['conteudo']), (string) ($selo['assinatura'] ?? ''), $chave['chave_publica']);
        return ['valido' => $ok, 'motivo' => $ok ? null : 'O conteúdo não confere com o selo: o manifesto foi alterado.'];
    }
}
