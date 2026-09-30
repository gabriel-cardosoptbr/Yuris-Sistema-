<?php

namespace App\WhatsAppAgente;

use App\Core\Database;

/**
 * PerfilComercial: o nome de uma conta COMERCIAL do WhatsApp, quando o WhatsApp
 * não o entrega.
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA, MEDIDO EM 30/09/2026
 * ---------------------------------------------------------------------------
 * Conta comercial (WhatsApp Business de empresa) manda `pushName` VAZIO nas
 * mensagens, não está na agenda, e o `fetchProfile` da Evolution devolve `name`
 * vazio. Resultado: a conversa aparece na lista só com o telefone. No canal do
 * Fleetiflow eram 4 de 10 conversas, todas concessionárias.
 *
 * O que a Evolution ENTREGA dessas contas é o perfil comercial
 * (`/chat/fetchBusinessProfile`): descrição, site, categoria, endereço. O nome
 * sai dali:
 *
 *   descrição "Fiat Sinal é uma empresa do Grupo Sinal..."  -> "Fiat Sinal"
 *   site      "https://www.carrera.com.br/comprar/..."      -> "Carrera"
 *
 * ---------------------------------------------------------------------------
 * É DEDUÇÃO, E POR ISSO TEM O PESO MAIS BAIXO
 * ---------------------------------------------------------------------------
 * Diferente do resto de `Identidade`, isto é heurística. Então o nome entra com
 * a origem `perfil_comercial`, que pesa MENOS que o pushName: só aparece quando
 * não há nada melhor, e qualquer nome real (pushName, agenda, CRM, rename
 * manual) o substitui. Na dúvida a função devolve null e a tela segue mostrando
 * o telefone, que é verdade. Nome errado é pior que nome nenhum.
 *
 * ---------------------------------------------------------------------------
 * UMA CONSULTA POR CONTATO, NÃO UMA POR ABERTURA DE TELA
 * ---------------------------------------------------------------------------
 * `whatsapp_identidades.perfil_comercial_em` (migration 132) guarda quando o
 * perfil foi consultado, tenha dado nome ou não. Sem isso, toda abertura do
 * Chat consultaria a Evolution de novo para cada conversa sem nome. Depois de
 * REVALIDAR_DIAS a consulta pode ser refeita (a empresa pode ter preenchido o
 * perfil).
 */
final class PerfilComercial
{
    public const REVALIDAR_DIAS = 30;

    /** Começo de frase que NÃO é nome de empresa ("Nossa missão é a..."). */
    private const INICIO_GENERICO = '/^(noss[oa]s?|aqui|est[ea]s?|ess[ea]s?|isso|isto|n[oó]s|seus?|suas?|meus?|minhas?|hoje|tudo|cada|quem|quando|onde|qualidade|atendimento|hor[aá]rio|miss[aã]o|objetivo|trabalho|bem|seja)\b/iu';

    /** Hosts em que o nome está no caminho, não no domínio. */
    private const REDES = ['instagram.com', 'facebook.com', 'fb.com', 'linkedin.com', 'tiktok.com', 'youtube.com', 'x.com', 'twitter.com'];

    /** Hosts que não dizem nada sobre a empresa. */
    private const IGNORAR = ['wa.me', 'api.whatsapp.com', 'whatsapp.com', 'bit.ly', 'linktr.ee', 'linktree.com', 'goo.gl', 'google.com', 'g.page', 'maps.app.goo.gl', 't.me', 'gmail.com'];

    /**
     * O nome que dá para tirar do perfil comercial, ou null. Pura.
     *
     * @param array $perfil resposta de /chat/fetchBusinessProfile
     */
    public static function nomeDoPerfil(array $perfil): ?string
    {
        return self::nomeDaDescricao((string) ($perfil['description'] ?? ''))
            ?? self::nomeDoSite($perfil['website'] ?? null);
    }

    /** "X é uma/um/a/o ..." no começo da descrição. */
    public static function nomeDaDescricao(string $descricao): ?string
    {
        $d = trim((string) preg_replace('/\s+/u', ' ', $descricao));
        if ($d === '') return null;

        if (!preg_match('/^(?:[AaOo]s? )?([\p{Lu}0-9][^.!?:;|\n]{1,38}?)\s+(?:é|são)\s+(?:uma?|a|o|as|os|referência|especializad[ao]s?|líder)\b/u', $d, $m)) {
            return null;
        }
        return self::validar($m[1]);
    }

    /** O nome que está no domínio (ou no caminho, em rede social). */
    public static function nomeDoSite(mixed $site): ?string
    {
        foreach ((array) $site as $url) {
            $url = trim((string) $url);
            if ($url === '') continue;
            if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $host = (string) preg_replace('/^(www|m|loja|site|app)\./', '', $host);
            if ($host === '' || in_array($host, self::IGNORAR, true)) continue;

            if (in_array($host, self::REDES, true)) {
                $seg = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))[0] ?? '';
                $nome = self::validar(self::legivel($seg));
            } else {
                // carrera.com.br -> carrera ; minha-loja.com -> minha loja
                $nome = self::validar(self::legivel(explode('.', $host)[0]));
            }
            if ($nome !== null) return $nome;
        }
        return null;
    }

    /**
     * Resolve e GRAVA o nome comercial de uma conversa 1:1, consultando a
     * Evolution no máximo uma vez a cada REVALIDAR_DIAS por contato.
     *
     * @return array{nome:?string, consultou:bool}
     */
    public static function resolver(int $accountId, int $instanceId, string $jid, EvolutionApiService $evo, string $instancia): array
    {
        $a = Identidade::analisarJid($jid);
        if ($a['tipo'] !== 'telefone' || !Identidade::telefoneValido($a['digitos'])) {
            return ['nome' => null, 'consultou' => false];
        }

        $pdo = Database::getConnection();
        $id  = Identidade::registrar($accountId, $instanceId, $a['jid'], null, $a['digitos'], null, 'perfil_comercial');
        if ($id === null) return ['nome' => null, 'consultou' => false];

        $st = $pdo->prepare('SELECT nome, perfil_comercial_em FROM whatsapp_identidades WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $atual = $st->fetch(\PDO::FETCH_ASSOC) ?: [];

        // Já tem nome (de qualquer origem): nada a buscar.
        if (trim((string) ($atual['nome'] ?? '')) !== '') {
            return ['nome' => (string) $atual['nome'], 'consultou' => false];
        }
        // Já consultado há pouco e não deu nome: não insiste.
        $em = $atual['perfil_comercial_em'] ?? null;
        if ($em && strtotime((string) $em) > time() - self::REVALIDAR_DIAS * 86400) {
            return ['nome' => null, 'consultou' => false];
        }

        $perfil = $evo->fetchBusinessProfile($instancia, $a['digitos']);

        // Erro de rede/Evolution: NÃO marca como consultado, para tentar de novo depois.
        if (!empty($perfil['_error']) || (int) ($perfil['_http'] ?? 200) >= 500) {
            return ['nome' => null, 'consultou' => false];
        }

        $nome = !empty($perfil['isBusiness']) ? self::nomeDoPerfil($perfil) : null;
        if ($nome !== null) {
            Identidade::registrarNome($id, $nome, 'perfil_comercial');
        }
        $pdo->prepare('UPDATE whatsapp_identidades SET perfil_comercial_em = NOW() WHERE id = ?')->execute([$id]);

        return ['nome' => $nome, 'consultou' => true];
    }

    /** "minha-loja_sp" -> "Minha Loja Sp". */
    private static function legivel(string $s): string
    {
        $s = trim((string) preg_replace('/[-_.]+/', ' ', $s));
        return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }

    /** Nome aceitável: 2 a 40 caracteres, até 5 palavras, com letra, sem começo genérico. */
    private static function validar(string $nome): ?string
    {
        $nome = trim($nome, " \t\n\r\0\x0B-–—,@");
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 40) return null;
        if (!preg_match('/\p{L}{2}/u', $nome)) return null;
        if (count(explode(' ', $nome)) > 5) return null;
        if (preg_match(self::INICIO_GENERICO, $nome)) return null;
        return $nome;
    }
}
