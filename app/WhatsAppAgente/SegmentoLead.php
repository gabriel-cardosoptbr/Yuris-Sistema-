<?php
namespace App\WhatsAppAgente;

/**
 * SegmentoLead: o que a primeira mensagem que a conta enviou diz sobre o lead.
 *
 * Na prospecção ativa da edição CRM, a abertura sai do robô com o nome da empresa na
 * saudação ("Olá, Sampaio e Dellova Campos Advogados! Tudo bem? Aqui é a Isa...") e
 * com palavras do segmento ("escritório" e Yuris para advocacia, "clínica" para
 * estética). O lead nasce só com o telefone, e a conversa aparecia "Sem setor" e só
 * com o número. Estas regras tiram dali o nome e o setor (pedido da Inovaize,
 * 07/10/2026). São puras, sem banco: quem grava é SdrFleetiflow::completarLead no
 * momento em que o card nasce, e os scripts de manutenção para o que já existia.
 *
 * Só vale o que tem prova: saudação no molde "Oi|Olá, NOME!" numa abertura de verdade
 * (não um "Olá, Maria! Obrigado" curto de quem atende), e segmento só quando UMA
 * família de palavras aparece. Ambíguo ou sem palavra, não marca.
 *
 * O setor só é sugerido entre os que a conta TEM (id => nome), e só para os nomes com
 * regra aqui: o setor da Fleet (Concessionária, Locação) vem do próprio robô, pelo
 * `setor` do sdr_etapa.php, e nada aqui mexe nele.
 */
final class SegmentoLead
{
    /** Palavras da mensagem de abertura => segmento (chave = nome do setor sem acento, minúsculo). */
    public const REGRAS = [
        'advocacia' => '/escrit[oó]rio|advoc|jur[ií]dic|yuris/iu',
        'estetica'  => '/cl[ií]nica|est[eé]tic|biom[eé]dic|harmoniza/iu',
    ];

    /** Texto do tipo do lead ("Clínica de Estética") => segmento. */
    public const TIPOS = [
        'advocacia' => '/advoc/iu',
        'estetica'  => '/est[eé]tic|cl[ií]nic/iu',
    ];

    /** Uma abertura de verdade tem mais que isto; resposta curta de quem atende não. */
    public const MIN_ABERTURA = 80;

    /** "Estética" => "estetica": a chave do setor para casar com REGRAS. */
    public static function chave(string $nome): string
    {
        $s = strtr(mb_strtolower(trim($nome)), ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i',
                                                'ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
        return trim((string)preg_replace('/[^a-z0-9]+/', '', $s));
    }

    /** Nome na saudação "Oi|Olá, NOME!", ou '' quando não dá para confiar. */
    public static function nomePelaSaudacao(string $msg): string
    {
        if (!preg_match('/^\s*(?:Oi|Olá|Ola)\s*,?\s+(.{2,90}?)\s*!/u', $msg, $m)) return '';
        $n = trim((string)preg_replace('/\s+/u', ' ', $m[1]));
        $n = trim((string)preg_replace('/^pessoal\s+d[aeo]s?\s+/iu', '', $n));
        if ($n === '' || !preg_match('/\p{L}/u', $n)) return '';
        if (preg_match('/^(tudo|pessoal|time|equipe|amigo|amiga|tudo bem|tudo certo|prezad[oa]s?)\b/iu', $n)) return '';
        return mb_substr($n, 0, 180);
    }

    /** Como nomePelaSaudacao, mas só para texto que parece uma abertura (MIN_ABERTURA). */
    public static function nomeDaAbertura(string $msg): string
    {
        return mb_strlen(trim($msg)) >= self::MIN_ABERTURA ? self::nomePelaSaudacao($msg) : '';
    }

    /**
     * O setor da conta que a mensagem indica, ou null.
     *
     * @param array<int,string> $setores id => nome dos setores da conta
     */
    public static function setorPelaMensagem(string $msg, array $setores): ?int
    {
        return self::escolher(self::REGRAS, $msg, $setores);
    }

    /**
     * O setor da conta que o tipo do lead indica ("Advocacia", "Clínica de Estética"), ou null.
     *
     * @param array<int,string> $setores id => nome dos setores da conta
     */
    public static function setorPeloTipo(?string $tipo, array $setores): ?int
    {
        return ($tipo !== null && trim($tipo) !== '') ? self::escolher(self::TIPOS, $tipo, $setores) : null;
    }

    /**
     * Segmentos que a Fleet atende, pelo NOME do lead (empresa), na ordem em que valem.
     * Os específicos vêm primeiro: "Locadora de Veículos" tem a palavra veículos, mas é
     * Locação. Concessionária é o setor geral de quem lida com veículos.
     */
    public const NOMES_ESPECIFICOS = [
        'despachante'    => '/despach|emplac|documenta[cç][aã]o\s+veicular/iu',
        'locacao'        => '/locad|loca[cç][aãoõ]|\brent(al)?\b|aluguel|alugue|aluga\b|localiza|movida|unidas/iu',
        'transportadora' => '/transport|log[ií]stic|translog|\bfretes?\b|\bcargas?\b|expresso|mudan[cç]as/iu',
        'telecom'        => '/telecom|telefon|internet|fibra|provedor|banda\s+larga/iu',
    ];
    public const NOMES_GERAL = [
        'concessionaria' => '/concession|ve[ií]cul|autom[oó]ve|seminov|multimarc|revenda|motors?\b|\bcar(s|ros)?\b|garage|garagem|loja\s+de\s+(carros|ve)|chevrolet|\bford\b|\bfiat\b|volkswagen|\bvw\b|toyota|honda|hyundai|renault|\bjeep\b|nissan|\bkia\b|citro[eë]n|peugeot|caoa|\bbyd\b|\bbmw\b|mercedes|\baudi\b|volvo|mitsubishi|suzuki|yamaha|triumph|harley|ducati|scania|kawasaki|jaguar|land\s+rover|porsche|lexus|subaru|ferrari|maserati|lamborghini|bentley|tesla|dodge|chrysler|chery|omoda|jaecoo|jac|ram|gwm|mini|mg|daf|iveco|motorsport|motorrad|motos?|dahruj|germ[aâ]nica|stefanini|saveiro|automotores/iu',
    ];

    /**
     * O setor da conta que o NOME do lead indica, ou null. Só vale o que tem prova:
     * duas famílias específicas no mesmo nome é ambíguo e não marca. Sem palavra
     * específica, o nome com cara de veículo vai para Concessionária.
     *
     * @param array<int,string> $setores id => nome dos setores da conta
     */
    public static function setorPeloNome(string $nome, array $setores): ?int
    {
        $nome = trim($nome);
        if ($nome === '') return null;
        $hits = array_keys(array_filter(self::NOMES_ESPECIFICOS, fn($re) => (bool)preg_match($re, $nome)));
        if (count($hits) > 1) return null;
        $familia = $hits[0] ?? (preg_match(self::NOMES_GERAL['concessionaria'], $nome) ? 'concessionaria' : null);
        if ($familia === null) return null;
        foreach ($setores as $id => $n) {
            if (self::chave((string)$n) === $familia) return (int)$id;
        }
        return null;
    }

    /** @param array<string,string> $regras */
    private static function escolher(array $regras, string $texto, array $setores): ?int
    {
        if ($texto === '') return null;
        // Duas famílias de palavras no mesmo texto: ambíguo, não marca (mesmo que a conta só tenha um dos setores).
        $familias = array_keys(array_filter($regras, fn($re) => (bool)preg_match($re, $texto)));
        if (count($familias) !== 1) return null;
        foreach ($setores as $id => $nome) {
            if (self::chave((string)$nome) === $familias[0]) return (int)$id;
        }
        return null;
    }
}
