<?php
namespace App\Projetos;

use App\Core\Database;

/**
 * Projetos: a carteira de projetos da conta, com o que cada um consome, quanto
 * arrecada e o que falta preencher (pedido da Inovaize, 14/10/2026).
 *
 * Tabelas `projeto_servidores`, `projetos` e `projeto_itens` (migration 146).
 * Regras:
 *
 *  - Toda leitura e escrita recebe a lista de contas acessíveis e filtra por
 *    ela, nas três tabelas. Projeto, item ou servidor de outra conta não existe
 *    para quem chama: devolve null ou false.
 *  - Valores em centavos dentro da classe e DECIMAL(12,2) no banco.
 *  - Item com valor NULL é "a preencher": o projeto consome aquilo, só falta o
 *    número. Conta como pendência e fica fora das somas (nunca vira zero).
 *  - Item em dólar é convertido pela cotação da conta
 *    (`configuracoes.projetos.cotacao_usd`), editável na tela.
 *  - Equivalente mensal: mensal = valor x quantidade; anual = /12; único não
 *    entra no mês, só no arrecadado.
 *  - Servidor compartilhado: o custo mensal dele é rateado entre os projetos
 *    não encerrados que rodam nele, pelo peso de cada um (servidor_peso).
 *  - Arrecadado é o previsto pelo contrato até hoje: cobranças da receita que
 *    já venceram desde o início dela (até o fim, se tiver). Não é extrato.
 *  - Totais, margem, arrecadado e pendências não são gravados: saem daqui.
 *  - Hierarquia (migration 147): projeto-mãe (`parent_id` NULL) junta os
 *    subprojetos (Arte Piedosa > streaming, site, B2B). Só um nível: subprojeto
 *    não tem filho, e projeto com filho não vira subprojeto. Os números da mãe
 *    são os dela somados aos dos filhos não encerrados (`agregado`).
 *  - Contas do projeto (App\Projetos\Conta) entram na receita (as que pagam) e
 *    no custo (custo direto); o custo compartilhado é rateado entre elas.
 *  - Evolução mensal (`evolucao`): receita e custo mês a mês pelo cadastro
 *    (vigência dos itens, início das contas e do projeto), para os gráficos.
 *  - O módulo é ligado por conta: `configuracoes.modulos.projetos = true`
 *    (habilitado()/ligar()). Só edição CRM; conta Yuris nunca.
 */
final class Projeto
{
    public const MAX_VALOR_CENTAVOS = 999999999;
    public const COTACAO_PADRAO = 5.50;

    public const TIPOS = [
        'saas' => 'SaaS próprio', 'cliente' => 'Projeto de cliente', 'interno' => 'Uso interno',
        'site' => 'Site', 'automacao' => 'Automação', 'outro' => 'Outro',
    ];
    public const STATUS = [
        'producao' => 'Em produção', 'desenvolvimento' => 'Em desenvolvimento',
        'pausado' => 'Pausado', 'encerrado' => 'Encerrado',
    ];
    public const CATEGORIAS = [
        'receita' => [
            'mensalidade' => 'Mensalidade', 'implantacao' => 'Implantação', 'manutencao' => 'Manutenção e suporte',
            'licenca' => 'Licença por usuário', 'outro' => 'Outra receita',
        ],
        'custo' => [
            'servidor' => 'Servidor e hospedagem', 'ia' => 'Inteligência artificial', 'whatsapp' => 'WhatsApp e mensagens',
            'api' => 'APIs e dados', 'dominio' => 'Domínio e DNS', 'usuarios' => 'Licença por usuário',
            'ferramenta' => 'Ferramentas e assinaturas', 'equipe' => 'Equipe e horas', 'outro' => 'Outro custo',
        ],
    ];
    public const RECORRENCIAS = ['mensal' => 'Mensal', 'anual' => 'Anual', 'unico' => 'Único'];
    public const MOEDAS = ['BRL', 'USD'];

    /* =================================================================
     * Módulo por conta
     * ================================================================= */

    /** O módulo vale para esta conta? Só edição CRM e com `configuracoes.modulos.projetos` ligado. */
    public static function habilitado(array $conta): bool
    {
        if (!$conta || \App\Master\Account::moduloJuridicoDisponivel($conta)) return false;
        $config = json_decode((string) ($conta['configuracoes'] ?? ''), true);
        return is_array($config) && ($config['modulos']['projetos'] ?? false) === true;
    }

    /** Liga ou desliga o módulo na conta, preservando o resto de `configuracoes`. */
    public static function ligar(\PDO $pdo, int $accountId, bool $ligado): void
    {
        self::mexerConfig($pdo, $accountId, function (array $config) use ($ligado): array {
            if (!is_array($config['modulos'] ?? null)) $config['modulos'] = [];
            if ($ligado) $config['modulos']['projetos'] = true; else unset($config['modulos']['projetos']);
            if (!$config['modulos']) unset($config['modulos']);
            return $config;
        });
    }

    /** Cotação do dólar usada para converter item em USD (padrão: COTACAO_PADRAO). */
    public static function cotacaoUsd(array $conta): float
    {
        $config = json_decode((string) ($conta['configuracoes'] ?? ''), true);
        $v = is_array($config) ? (float) ($config['projetos']['cotacao_usd'] ?? 0) : 0.0;
        return $v > 0 ? round($v, 4) : self::COTACAO_PADRAO;
    }

    /** Grava a cotação (entre 0,01 e 100). Devolve false se inválida. */
    public static function salvarCotacao(\PDO $pdo, int $accountId, $valor): bool
    {
        $c = self::centavos($valor);
        if ($c === null || $c < 1 || $c > 10000) return false;
        self::mexerConfig($pdo, $accountId, function (array $config) use ($c): array {
            if (!is_array($config['projetos'] ?? null)) $config['projetos'] = [];
            $config['projetos']['cotacao_usd'] = $c / 100.0;
            return $config;
        });
        return true;
    }

    private static function mexerConfig(\PDO $pdo, int $accountId, callable $fn): void
    {
        $st = $pdo->prepare('SELECT configuracoes FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$accountId]);
        $config = json_decode((string) $st->fetchColumn(), true);
        if (!is_array($config)) $config = [];
        $config = $fn($config);
        $pdo->prepare('UPDATE accounts SET configuracoes = ?, updated_at = NOW() WHERE id = ?')
            ->execute([json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $accountId]);
    }

    /* =================================================================
     * Conversões e validação
     * ================================================================= */

    /** "1.234,56", "1234,56", "1234.56" ou número => centavos; null se vazio ou inválido. */
    public static function centavos($valor): ?int
    {
        if ($valor === null) return null;
        if (is_int($valor) || is_float($valor)) return $valor < 0 ? null : (int) round($valor * 100);
        $s = trim((string) $valor);
        $s = (string) preg_replace('/^(R\$|US\$|\$)\s*/', '', $s);
        if ($s === '') return null;
        if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
        return (int) round(((float) $s) * 100);
    }

    /** Quantidade ("2", "2,5", 3) com até 2 casas; null se inválida. */
    public static function quantidade($valor): ?float
    {
        if ($valor === null || $valor === '') return 1.0;
        $s = str_replace(',', '.', trim((string) $valor));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
        $q = round((float) $s, 2);
        return ($q <= 0 || $q > 99999999) ? null : $q;
    }

    /** Nome => slug (minúsculas, sem acento, hífen). */
    public static function slug(string $nome): string
    {
        $s = mb_strtolower(trim($nome), 'UTF-8');
        $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e','í'=>'i','î'=>'i','ì'=>'i','ï'=>'i',
                        'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o','ú'=>'u','û'=>'u','ù'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        return substr(trim($s, '-'), 0, 80) ?: 'projeto';
    }

    /** Hoje no horário de Brasília (o banco e o PHP rodam em UTC). */
    public static function hoje(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    }

    private static function dataValida(string $d): bool
    {
        $o = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $o !== false && $o->format('Y-m-d') === $d && $d >= '2000-01-01' && $d <= '2100-12-31';
    }

    /** Texto aparado, null se vazio, false se passa do limite. */
    private static function texto($v, int $max): string|null|false
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') return null;
        return mb_strlen($s) > $max ? false : $s;
    }

    /**
     * Valida o projeto vindo do formulário.
     *
     * @return array{0:?array,1:?string} [dados, erro]
     */
    public static function validarProjeto(array $in): array
    {
        $nome = self::texto($in['nome'] ?? '', 150);
        if ($nome === null) return [null, 'Informe o nome do projeto.'];
        if ($nome === false) return [null, 'O nome passa de 150 caracteres.'];
        $tipo = (string) ($in['tipo'] ?? 'cliente');
        if (!isset(self::TIPOS[$tipo])) return [null, 'Tipo de projeto inválido.'];
        $status = (string) ($in['status'] ?? 'producao');
        if (!isset(self::STATUS[$status])) return [null, 'Situação inválida.'];

        $campos = ['cliente' => 150, 'url' => 255, 'repositorio' => 255, 'stack' => 255, 'responsavel' => 150,
                   'descricao' => 4000, 'observacao' => 4000];
        $d = ['nome' => $nome, 'tipo' => $tipo, 'status' => $status];
        foreach ($campos as $c => $max) {
            $v = self::texto($in[$c] ?? null, $max);
            if ($v === false) return [null, "O campo $c passa de $max caracteres."];
            $d[$c] = $v;
        }
        $inicio = trim((string) ($in['inicio'] ?? ''));
        if ($inicio !== '' && !self::dataValida($inicio)) return [null, 'Data de início inválida.'];
        $d['inicio'] = $inicio === '' ? null : $inicio;

        $usu = trim((string) ($in['usuarios_ativos'] ?? ''));
        if ($usu !== '' && (!ctype_digit($usu) || (int) $usu > 10000000)) return [null, 'Usuários ativos precisa ser um número inteiro.'];
        $d['usuarios_ativos'] = $usu === '' ? null : (int) $usu;

        $srv = (int) ($in['servidor_id'] ?? 0);
        $d['servidor_id'] = $srv > 0 ? $srv : null;
        $peso = (int) ($in['servidor_peso'] ?? 1);
        if ($peso < 1 || $peso > 100) return [null, 'O peso no servidor vai de 1 a 100.'];
        $d['servidor_peso'] = $peso;
        $pai = (int) ($in['parent_id'] ?? 0);
        $d['parent_id'] = $pai > 0 ? $pai : null;
        return [$d, null];
    }

    /**
     * Valida um item (receita ou custo). Valor vazio é aceito: fica "a preencher".
     *
     * @return array{0:?array,1:?string}
     */
    public static function validarItem(array $in): array
    {
        $nat = (string) ($in['natureza'] ?? '');
        if (!isset(self::CATEGORIAS[$nat])) return [null, 'Informe se é receita ou custo.'];
        $cat = (string) ($in['categoria'] ?? 'outro');
        if (!isset(self::CATEGORIAS[$nat][$cat])) return [null, 'Categoria inválida.'];
        $desc = self::texto($in['descricao'] ?? '', 255);
        if ($desc === null) return [null, 'Descreva o item.'];
        if ($desc === false) return [null, 'A descrição passa de 255 caracteres.'];
        $forn = self::texto($in['fornecedor'] ?? null, 150);
        if ($forn === false) return [null, 'O fornecedor passa de 150 caracteres.'];
        $obs = self::texto($in['observacao'] ?? null, 2000);
        if ($obs === false) return [null, 'A observação passa de 2.000 caracteres.'];

        $bruto = $in['valor_unitario'] ?? null;
        $cent = null;
        if ($bruto !== null && trim((string) $bruto) !== '') {
            $cent = self::centavos($bruto);
            if ($cent === null) return [null, 'Valor inválido. Use o formato 1.234,56.'];
            if ($cent > self::MAX_VALOR_CENTAVOS) return [null, 'Valor alto demais.'];
        }
        $qtd = self::quantidade($in['quantidade'] ?? 1);
        if ($qtd === null) return [null, 'Quantidade inválida.'];
        $moeda = strtoupper((string) ($in['moeda'] ?? 'BRL'));
        if (!in_array($moeda, self::MOEDAS, true)) return [null, 'Moeda inválida.'];
        $rec = (string) ($in['recorrencia'] ?? 'mensal');
        if (!isset(self::RECORRENCIAS[$rec])) return [null, 'Recorrência inválida.'];

        $ini = trim((string) ($in['inicio'] ?? ''));
        $fim = trim((string) ($in['fim'] ?? ''));
        if ($ini !== '' && !self::dataValida($ini)) return [null, 'Data de início inválida.'];
        if ($fim !== '' && !self::dataValida($fim)) return [null, 'Data de fim inválida.'];
        if ($ini !== '' && $fim !== '' && $fim < $ini) return [null, 'O fim não pode vir antes do início.'];

        return [[
            'natureza' => $nat, 'categoria' => $cat, 'descricao' => $desc, 'fornecedor' => $forn,
            'valor_centavos' => $cent, 'quantidade' => $qtd, 'moeda' => $moeda, 'recorrencia' => $rec,
            'inicio' => $ini === '' ? null : $ini, 'fim' => $fim === '' ? null : $fim, 'observacao' => $obs,
        ], null];
    }

    /** @return array{0:?array,1:?string} */
    public static function validarServidor(array $in): array
    {
        $nome = self::texto($in['nome'] ?? '', 150);
        if ($nome === null) return [null, 'Informe o nome do servidor.'];
        if ($nome === false) return [null, 'O nome passa de 150 caracteres.'];
        $prov = self::texto($in['provedor'] ?? null, 100);
        $end = self::texto($in['endereco'] ?? null, 255);
        $obs = self::texto($in['observacao'] ?? null, 2000);
        if ($prov === false || $end === false || $obs === false) return [null, 'Algum campo passou do limite de tamanho.'];
        $bruto = $in['valor_mensal'] ?? null;
        $cent = null;
        if ($bruto !== null && trim((string) $bruto) !== '') {
            $cent = self::centavos($bruto);
            if ($cent === null) return [null, 'Valor mensal inválido. Use o formato 1.234,56.'];
            if ($cent > self::MAX_VALOR_CENTAVOS) return [null, 'Valor alto demais.'];
        }
        $moeda = strtoupper((string) ($in['moeda'] ?? 'BRL'));
        if (!in_array($moeda, self::MOEDAS, true)) return [null, 'Moeda inválida.'];
        return [['nome' => $nome, 'provedor' => $prov, 'endereco' => $end, 'valor_centavos' => $cent,
                 'moeda' => $moeda, 'observacao' => $obs], null];
    }

    /* =================================================================
     * Contas (puras, sem banco: testadas em scripts/tests/projetos_test.php)
     * ================================================================= */

    /** Valor total do item em centavos de real (valor x quantidade, convertido). null = a preencher. */
    public static function totalItemBrl(array $item, float $cotacao): ?int
    {
        if ($item['valor_unitario'] === null || $item['valor_unitario'] === '') return null;
        $c = (int) round(((float) $item['valor_unitario']) * 100 * (float) ($item['quantidade'] ?? 1));
        if (($item['moeda'] ?? 'BRL') === 'USD') $c = (int) round($c * $cotacao);
        return $c;
    }

    /** O item vale hoje? Sem início = já vale; sem fim = vale sempre. */
    public static function vigente(array $item, string $hoje): bool
    {
        if (!empty($item['inicio']) && $item['inicio'] > $hoje) return false;
        if (!empty($item['fim']) && $item['fim'] < $hoje) return false;
        return true;
    }

    /** Equivalente mensal hoje, em centavos (0 se único ou fora da vigência; null se a preencher). */
    public static function mensal(array $item, float $cotacao, string $hoje): ?int
    {
        $t = self::totalItemBrl($item, $cotacao);
        if ($t === null) return null;
        if (!self::vigente($item, $hoje)) return 0;
        return match ($item['recorrencia']) {
            'mensal' => $t,
            'anual' => (int) round($t / 12),
            default => 0,
        };
    }

    /**
     * Quantas cobranças venceram de $inicio até $ate (inclusive), a cada $passo
     * meses (1 = mensal, 12 = anual). A primeira vence no próprio início.
     */
    public static function cobrancasVencidas(string $inicio, string $ate, int $passo): int
    {
        if ($ate < $inicio) return 0;
        [$a1, $m1, $d1] = array_map('intval', explode('-', $inicio));
        [$a2, $m2, $d2] = array_map('intval', explode('-', $ate));
        $meses = ($a2 - $a1) * 12 + ($m2 - $m1);
        // Dia 31 em mês de 30: a cobrança cai no último dia do mês.
        $ultimoDia = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $a2, $m2)))->format('t');
        if ($d2 < min($d1, $ultimoDia)) $meses--;
        return intdiv(max(0, $meses), $passo) + 1;
    }

    /** Arrecadado pelo contrato até hoje (centavos). null = não dá para calcular (sem valor ou sem início). */
    public static function arrecadado(array $item, float $cotacao, string $hoje): ?int
    {
        if ($item['natureza'] !== 'receita') return 0;
        $t = self::totalItemBrl($item, $cotacao);
        if ($t === null || empty($item['inicio'])) return null;
        $ate = (!empty($item['fim']) && $item['fim'] < $hoje) ? $item['fim'] : $hoje;
        return match ($item['recorrencia']) {
            'mensal' => $t * self::cobrancasVencidas($item['inicio'], $ate, 1),
            'anual' => $t * self::cobrancasVencidas($item['inicio'], $ate, 12),
            default => $item['inicio'] <= $hoje ? $t : 0,
        };
    }

    /**
     * Rateio do custo de cada servidor entre os projetos não encerrados que
     * rodam nele, pelo peso. Os centavos que sobram vão para os primeiros.
     *
     * @param list<array> $servidores  com id, valor_mensal, moeda
     * @param list<array> $projetos    com id, servidor_id, servidor_peso, status
     * @return array<int,?int> projeto_id => centavos (null = servidor sem custo informado)
     */
    public static function rateioServidores(array $servidores, array $projetos, float $cotacao): array
    {
        $porServidor = [];
        foreach ($projetos as $p) {
            if (empty($p['servidor_id']) || $p['status'] === 'encerrado') continue;
            $porServidor[(int) $p['servidor_id']][] = $p;
        }
        $out = [];
        foreach ($servidores as $s) {
            $lista = $porServidor[(int) $s['id']] ?? [];
            if (!$lista) continue;
            if ($s['valor_mensal'] === null || $s['valor_mensal'] === '') {
                foreach ($lista as $p) $out[(int) $p['id']] = null;
                continue;
            }
            $total = (int) round(((float) $s['valor_mensal']) * 100);
            if (($s['moeda'] ?? 'BRL') === 'USD') $total = (int) round($total * $cotacao);
            $pesos = array_map(fn($p) => max(1, (int) $p['servidor_peso']), $lista);
            $soma = array_sum($pesos);
            $partes = []; $distribuido = 0;
            foreach ($pesos as $i => $w) { $partes[$i] = intdiv($total * $w, $soma); $distribuido += $partes[$i]; }
            for ($i = 0; $distribuido < $total; $i++, $distribuido++) $partes[$i % count($partes)]++;
            foreach ($lista as $i => $p) $out[(int) $p['id']] = $partes[$i];
        }
        return $out;
    }

    /**
     * Números e pendências de um projeto.
     *
     * @param ?int  $custoServidor centavos do rateio; null = servidor sem custo
     * @param ?array $servidor     o servidor ligado (ou null)
     */
    public static function calcular(array $p, array $itens, ?int $custoServidor, ?array $servidor, float $cotacao, string $hoje,
                                    array $contas = [], bool $temFilhos = false): array
    {
        $receita = 0; $custoItens = 0; $arrec = 0; $unicoReceita = 0;
        $porCategoria = [];
        $aPreencher = []; $semInicio = [];
        $temReceita = false; $temCustoServidorItem = false;
        foreach ($itens as $it) {
            $m = self::mensal($it, $cotacao, $hoje);
            if ($it['natureza'] === 'receita') $temReceita = true;
            if ($it['natureza'] === 'custo' && $it['categoria'] === 'servidor') $temCustoServidorItem = true;
            if ($m === null) { $aPreencher[] = $it['descricao']; continue; }
            if ($it['natureza'] === 'receita') {
                $receita += $m;
                $a = self::arrecadado($it, $cotacao, $hoje);
                if ($a === null) $semInicio[] = $it['descricao']; else $arrec += $a;
                if ($it['recorrencia'] === 'unico') $unicoReceita += (int) self::totalItemBrl($it, $cotacao);
            } else {
                $custoItens += $m;
                $porCategoria[$it['categoria']] = ($porCategoria[$it['categoria']] ?? 0) + $m;
            }
        }
        if ($custoServidor !== null && $custoServidor > 0) {
            $porCategoria['servidor'] = ($porCategoria['servidor'] ?? 0) + $custoServidor;
        }
        // Contas: o custo compartilhado (itens + servidor) é rateado entre elas; a
        // receita das que pagam e o custo direto de cada uma somam no projeto.
        $compartilhado = $custoItens + (int) $custoServidor;
        $rateio = Conta::rateio($compartilhado, $contas, $cotacao);
        $receitaContas = 0; $diretos = 0; $semReceita = 0; $usuContas = 0; $temUsuContas = false; $porConta = [];
        foreach ($contas as $c) {
            $r = $rateio[(int) $c['id']];
            $pagante = in_array($c['status'], Conta::PAGANTES, true);
            if ($pagante && $r['receita'] === null) $semReceita++;
            $receitaContas += (int) $r['receita'];
            $diretos += $r['direto'];
            if (Conta::consome($c) && $c['usuarios'] !== null && $c['usuarios'] !== '') { $usuContas += (int) $c['usuarios']; $temUsuContas = true; }
            $u = (int) ($c['usuarios'] ?? 0);
            $porConta[(int) $c['id']] = [
                'receita' => $r['receita'] === null ? null : $r['receita'] / 100.0, 'custo' => $r['custo'] / 100.0,
                'custo_direto' => $r['direto'] / 100.0,
                'margem' => $r['receita'] === null ? null : ($r['receita'] - $r['custo']) / 100.0,
                'custo_por_usuario' => $u > 0 ? round($r['custo'] / $u / 100.0, 2) : null,
            ];
        }
        if ($diretos > 0) $porCategoria['contas'] = ($porCategoria['contas'] ?? 0) + $diretos;
        $receita += $receitaContas;
        $custo = $compartilhado + $diretos;
        $margem = $receita - $custo;

        // Checklist do dossiê: o que se aplica a este projeto, e se está preenchido.
        // Projeto-mãe com subprojetos: servidor, endereço, stack, receita, usuários
        // e custos são cobrados de cada subprojeto, não da mãe (seria pendência dobrada).
        $cobra = in_array($p['tipo'], ['cliente', 'saas'], true) && $p['status'] !== 'encerrado' && !$temFilhos;
        $checks = [];
        $checks[] = ['Descrição do projeto', !empty($p['descricao'])];
        if ($p['tipo'] === 'cliente') $checks[] = ['Cliente', !empty($p['cliente'])];
        if (!$temFilhos) {
            if ($p['status'] === 'producao' && $p['tipo'] !== 'automacao') $checks[] = ['Endereço de produção', !empty($p['url'])];
            $checks[] = ['Onde roda (servidor)', !empty($p['servidor_id']) || $temCustoServidorItem];
            $checks[] = ['Stack (tecnologia)', !empty($p['stack'])];
        }
        if ($servidor) $checks[] = ['Custo do servidor ' . $servidor['nome'], $servidor['valor_mensal'] !== null && $servidor['valor_mensal'] !== ''];
        $checks[] = ['Responsável', !empty($p['responsavel'])];
        $temUsuProjeto = $p['usuarios_ativos'] !== null && $p['usuarios_ativos'] !== '';
        if ($cobra) {
            $checks[] = ['Receita cadastrada', $temReceita || $receitaContas > 0];
            $checks[] = ['Usuários ativos', $temUsuProjeto || $temUsuContas];
        }
        if (!$temFilhos) $checks[] = ['Custos cadastrados', $custo > 0 || $aPreencher || !empty($p['servidor_id'])];
        foreach ($aPreencher as $d) $checks[] = ['Valor de ' . $d, false];
        foreach ($semInicio as $d) $checks[] = ['Início da cobrança de ' . $d, false];
        if ($semReceita) $checks[] = ['Quanto paga' . ($semReceita === 1 ? ' 1 conta ativa' : " cada uma das $semReceita contas ativas"), false];

        $pend = []; $ok = 0;
        foreach ($checks as [$rotulo, $feito]) { if ($feito) $ok++; else $pend[] = $rotulo; }
        // Usuários: o número do projeto, se informado; senão a soma das contas.
        $usuarios = $temUsuProjeto ? (int) $p['usuarios_ativos'] : $usuContas;

        return [
            'receita_mensal' => $receita / 100.0,
            'custo_mensal' => $custo / 100.0,
            'custo_servidor' => $custoServidor === null ? null : $custoServidor / 100.0,
            'custo_itens' => $custoItens / 100.0,
            'margem_mensal' => $margem / 100.0,
            'margem_pct' => $receita > 0 ? round($margem / $receita * 100, 1) : null,
            'custo_por_usuario' => $usuarios > 0 ? round($custo / $usuarios / 100.0, 2) : null,
            'receita_por_usuario' => $usuarios > 0 ? round($receita / $usuarios / 100.0, 2) : null,
            'arrecadado' => $arrec / 100.0,
            'receita_unica' => $unicoReceita / 100.0,
            'custo_por_categoria' => array_map(fn($c) => $c / 100.0, $porCategoria),
            'custo_compartilhado' => $compartilhado / 100.0,
            'usuarios' => ($temUsuProjeto || $temUsuContas) ? $usuarios : null,
            'qtd_contas' => count(array_filter($contas, [Conta::class, 'consome'])),
            'contas' => $porConta,
            'pendencias' => $pend,
            'checks_ok' => $ok, 'checks_total' => count($checks),
            'completude' => $checks ? (int) round($ok / count($checks) * 100) : 100,
        ];
    }

    /**
     * Receita e custo mês a mês nos últimos $meses meses (centavos), pelo
     * cadastro: item vale no mês em que está vigente (único só no mês do início,
     * e sem início não entra); conta vale a partir do início dela (sem início,
     * todos os meses); o servidor, a partir do início do projeto (sem início,
     * todos os meses). É o previsto pelo cadastro, não extrato.
     *
     * @return list<array{mes:string,receita:int,custo:int}>
     */
    public static function evolucao(array $p, array $itens, array $contas, ?int $custoServidor, float $cotacao, string $hoje, int $meses = 12): array
    {
        $base = (new \DateTimeImmutable(substr($hoje, 0, 7) . '-01'))->modify('-' . ($meses - 1) . ' months');
        $out = [];
        for ($i = 0; $i < $meses; $i++) {
            $m = $base->modify("+$i months");
            $ini = $m->format('Y-m-d'); $fim = $m->format('Y-m-t');
            $rec = 0; $cus = 0;
            foreach ($itens as $it) {
                $t = self::totalItemBrl($it, $cotacao);
                if ($t === null) continue;
                if ($it['recorrencia'] === 'unico') {
                    if (empty($it['inicio']) || $it['inicio'] < $ini || $it['inicio'] > $fim) continue;
                    $v = $t;
                } else {
                    if (!empty($it['inicio']) && $it['inicio'] > $fim) continue;
                    if (!empty($it['fim']) && $it['fim'] < $ini) continue;
                    $v = $it['recorrencia'] === 'anual' ? (int) round($t / 12) : $t;
                }
                if ($it['natureza'] === 'receita') $rec += $v; else $cus += $v;
            }
            if ($custoServidor && (empty($p['inicio']) || $p['inicio'] <= $fim)) $cus += $custoServidor;
            foreach ($contas as $c) {
                if (!empty($c['inicio']) && $c['inicio'] > $fim) continue;
                if (in_array($c['status'], Conta::PAGANTES, true)) $rec += (int) Conta::brl($c['receita_mensal'], $c['moeda'], $cotacao);
                if (Conta::consome($c)) $cus += (int) Conta::brl($c['custo_direto'], 'BRL', $cotacao);
            }
            $out[] = ['mes' => $m->format('Y-m'), 'receita' => $rec, 'custo' => $cus];
        }
        return $out;
    }

    /* =================================================================
     * Leitura
     * ================================================================= */

    /** @return array{0:string,1:array<string,int>} [IN (...), parâmetros] */
    private static function inContas(array $contas, string $pref = 'pc'): array
    {
        $contas = array_values(array_unique(array_map('intval', $contas))) ?: [0];
        $ph = []; $p = [];
        foreach ($contas as $i => $id) { $ph[] = ":{$pref}{$i}"; $p["{$pref}{$i}"] = $id; }
        return ['(' . implode(',', $ph) . ')', $p];
    }

    /**
     * Tudo que a tela precisa: projetos (cada um com itens, contas, acessos sem
     * senha, números, pendências e evolução; os projetos-mãe também com o
     * `agregado` deles + filhos), servidores (com os projetos de cada um) e o
     * resumo da carteira.
     */
    public static function painel(array $contas, float $cotacao, ?string $hoje = null): array
    {
        $hoje ??= self::hoje();
        $pdo = Database::getConnection();
        [$in, $p] = self::inContas($contas);

        $st = $pdo->prepare("SELECT id, account_id, nome, provedor, endereco, valor_mensal, moeda, observacao
                               FROM projeto_servidores WHERE account_id IN $in AND deleted_at IS NULL ORDER BY nome");
        $st->execute($p);
        $servidores = $st->fetchAll(\PDO::FETCH_ASSOC);

        $st = $pdo->prepare("SELECT id, account_id, parent_id, slug, nome, cliente, tipo, status, descricao, url, repositorio, stack,
                                    servidor_id, servidor_peso, usuarios_ativos, responsavel, inicio, observacao, updated_at
                               FROM projetos WHERE account_id IN $in AND deleted_at IS NULL
                           ORDER BY FIELD(status, 'producao', 'desenvolvimento', 'pausado', 'encerrado'), nome");
        $st->execute($p);
        $projetos = $st->fetchAll(\PDO::FETCH_ASSOC);
        $idsValidos = array_flip(array_map(fn($x) => (int) $x['id'], $projetos));
        $maes = [];
        // Só conta como mãe (pendências ficam nos filhos) quem tem subprojeto ativo.
        foreach ($projetos as $x) {
            if ($x['parent_id'] && $x['status'] !== 'encerrado' && isset($idsValidos[(int) $x['parent_id']])) $maes[(int) $x['parent_id']] = true;
        }

        $porProjeto = function (string $sql) use ($pdo, $p): array {
            $st = $pdo->prepare($sql);
            $st->execute($p);
            $out = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $l) $out[(int) $l['projeto_id']][] = $l;
            return $out;
        };
        $itensPorProjeto = $porProjeto("SELECT id, projeto_id, natureza, categoria, descricao, fornecedor, valor_unitario, quantidade,
                                               moeda, recorrencia, inicio, fim, observacao
                                          FROM projeto_itens WHERE account_id IN $in AND deleted_at IS NULL
                                      ORDER BY natureza DESC, categoria, descricao");
        $contasPorProjeto = $porProjeto("SELECT id, projeto_id, nome, identificador, plano, status, receita_mensal, moeda, usuarios,
                                                peso_consumo, custo_direto, inicio, metricas, origem, sincronizado_em, observacao
                                           FROM projeto_contas WHERE account_id IN $in AND deleted_at IS NULL
                                       ORDER BY FIELD(status, 'ativa', 'inadimplente', 'teste', 'cancelada'), nome");
        $acessosPorProjeto = $porProjeto("SELECT id, projeto_id, rotulo, url, usuario, (senha_enc IS NOT NULL) AS tem_senha, observacao
                                            FROM projeto_acessos WHERE account_id IN $in AND deleted_at IS NULL ORDER BY rotulo");

        $srvPorId = [];
        foreach ($servidores as $s) $srvPorId[(int) $s['id']] = $s;
        $rateio = self::rateioServidores($servidores, $projetos, $cotacao);

        $saida = [];
        $tot = ['ativos' => 0, 'total' => 0, 'receita_mensal' => 0, 'custo_mensal' => 0, 'arrecadado' => 0,
                'pendencias' => 0, 'usuarios' => 0, 'sem_receita' => 0, 'contas' => 0];
        foreach ($projetos as $pr) {
            $id = (int) $pr['id'];
            // Mãe apagada ou de outra conta: o projeto sobe para o primeiro nível.
            if ($pr['parent_id'] && !isset($idsValidos[(int) $pr['parent_id']])) $pr['parent_id'] = null;
            $srv = $pr['servidor_id'] ? ($srvPorId[(int) $pr['servidor_id']] ?? null) : null;
            if (!$srv) $pr['servidor_id'] = null; // servidor apagado ou de outra conta: some do projeto
            // Fora do rateio (projeto encerrado) não paga servidor; null = servidor sem custo informado.
            $custoSrv = ($srv && array_key_exists($id, $rateio)) ? $rateio[$id] : 0;
            $itens = $itensPorProjeto[$id] ?? [];
            $ctas = $contasPorProjeto[$id] ?? [];
            $calc = self::calcular($pr, $itens, $custoSrv, $srv, $cotacao, $hoje, $ctas, isset($maes[$id]));
            $evo = self::evolucao($pr, $itens, $ctas, $custoSrv, $cotacao, $hoje);
            $saida[] = self::formatarProjeto($pr, $itens, $srv, $calc, $cotacao, $hoje, $ctas, $acessosPorProjeto[$id] ?? [], $evo);

            $tot['total']++;
            if ($pr['status'] === 'encerrado') continue;
            if ($pr['status'] === 'producao') $tot['ativos']++;
            $tot['receita_mensal'] += (int) round($calc['receita_mensal'] * 100);
            // Servidor entra pela lista de servidores (inteiro), não pelo rateio.
            $tot['custo_mensal'] += (int) round(($calc['custo_mensal'] - (float) $calc['custo_servidor']) * 100);
            $tot['arrecadado'] += (int) round($calc['arrecadado'] * 100);
            $tot['pendencias'] += count($calc['pendencias']);
            $tot['usuarios'] += (int) ($calc['usuarios'] ?? 0);
            $tot['contas'] += $calc['qtd_contas'];
            if (in_array($pr['tipo'], ['cliente', 'saas'], true) && $calc['receita_mensal'] <= 0) $tot['sem_receita']++;
        }
        $saida = self::agregar($saida);

        // Servidor entra inteiro no custo da carteira, mesmo sem projeto ligado:
        // a fatura chega do mesmo jeito.
        $srvSaida = []; $srvSemValor = 0;
        foreach ($servidores as $s) {
            $valor = null;
            if ($s['valor_mensal'] !== null) {
                $valor = (int) round(((float) $s['valor_mensal']) * 100);
                if ($s['moeda'] === 'USD') $valor = (int) round($valor * $cotacao);
                $tot['custo_mensal'] += $valor;
            } else {
                $srvSemValor++;
            }
            $nos = array_values(array_filter($saida, fn($x) => (int) $x['servidor_id'] === (int) $s['id']));
            $srvSaida[] = [
                'id' => (int) $s['id'], 'nome' => $s['nome'], 'provedor' => $s['provedor'], 'endereco' => $s['endereco'],
                'valor_mensal' => $s['valor_mensal'] === null ? null : round((float) $s['valor_mensal'], 2),
                'moeda' => $s['moeda'], 'valor_mensal_brl' => $valor === null ? null : $valor / 100.0,
                'observacao' => $s['observacao'],
                'projetos' => array_map(fn($x) => ['id' => $x['id'], 'nome' => $x['nome'], 'peso' => $x['servidor_peso'],
                                                    'rateio' => $x['calc']['custo_servidor']], $nos),
            ];
        }
        $tot['pendencias'] += $srvSemValor;

        $receita = $tot['receita_mensal']; $custo = $tot['custo_mensal'];
        return [
            'projetos' => $saida,
            'servidores' => $srvSaida,
            'resumo' => [
                'projetos_ativos' => $tot['ativos'], 'projetos_total' => $tot['total'],
                'projetos_mae' => count(array_filter($saida, fn($x) => $x['parent_id'] === null)),
                'receita_mensal' => $receita / 100.0, 'custo_mensal' => $custo / 100.0,
                'margem_mensal' => ($receita - $custo) / 100.0,
                'margem_pct' => $receita > 0 ? round(($receita - $custo) / $receita * 100, 1) : null,
                'arrecadado' => $tot['arrecadado'] / 100.0, 'pendencias' => $tot['pendencias'],
                'usuarios' => $tot['usuarios'], 'contas' => $tot['contas'], 'sem_receita' => $tot['sem_receita'],
                'servidores_sem_valor' => $srvSemValor,
            ],
        ];
    }

    /**
     * Em cada projeto, `filhos` (ids dos subprojetos) e `agregado`: os números
     * dele somados aos dos subprojetos não encerrados (projeto sem filho: os
     * próprios números). A evolução é somada mês a mês.
     */
    public static function agregar(array $projetos): array
    {
        $filhos = [];
        foreach ($projetos as $x) if ($x['parent_id']) $filhos[$x['parent_id']][] = $x;
        foreach ($projetos as &$x) {
            $grupo = [$x];
            foreach ($filhos[$x['id']] ?? [] as $f) if ($f['status'] !== 'encerrado' || $x['status'] === 'encerrado') $grupo[] = $f;
            $a = ['receita_mensal' => 0.0, 'custo_mensal' => 0.0, 'arrecadado' => 0.0, 'pendencias' => 0, 'usuarios' => 0,
                  'qtd_contas' => 0, 'checks_ok' => 0, 'checks_total' => 0, 'custo_por_categoria' => [], 'evolucao' => []];
            foreach ($grupo as $g) {
                $c = $g['calc'];
                foreach (['receita_mensal', 'custo_mensal', 'arrecadado'] as $k) $a[$k] += $c[$k];
                $a['pendencias'] += count($c['pendencias']);
                $a['usuarios'] += (int) ($c['usuarios'] ?? 0);
                $a['qtd_contas'] += $c['qtd_contas'];
                $a['checks_ok'] += $c['checks_ok']; $a['checks_total'] += $c['checks_total'];
                foreach ($c['custo_por_categoria'] as $k => $v) $a['custo_por_categoria'][$k] = ($a['custo_por_categoria'][$k] ?? 0) + $v;
                foreach ($g['evolucao'] as $i => $e) {
                    $a['evolucao'][$i] ??= ['mes' => $e['mes'], 'receita' => 0.0, 'custo' => 0.0];
                    $a['evolucao'][$i]['receita'] += $e['receita']; $a['evolucao'][$i]['custo'] += $e['custo'];
                }
            }
            foreach (['receita_mensal', 'custo_mensal', 'arrecadado'] as $k) $a[$k] = round($a[$k], 2);
            $a['margem_mensal'] = round($a['receita_mensal'] - $a['custo_mensal'], 2);
            $a['margem_pct'] = $a['receita_mensal'] > 0 ? round($a['margem_mensal'] / $a['receita_mensal'] * 100, 1) : null;
            $a['custo_por_usuario'] = $a['usuarios'] > 0 ? round($a['custo_mensal'] / $a['usuarios'], 2) : null;
            $a['completude'] = $a['checks_total'] ? (int) round($a['checks_ok'] / $a['checks_total'] * 100) : 100;
            $a['subprojetos'] = count($grupo) - 1;
            $x['filhos'] = array_map(fn($f) => $f['id'], $filhos[$x['id']] ?? []);
            $x['agregado'] = $a;
        }
        unset($x);
        return $projetos;
    }

    private static function formatarProjeto(array $p, array $itens, ?array $srv, array $calc, float $cotacao, string $hoje,
                                            array $contas = [], array $acessos = [], array $evolucao = []): array
    {
        return [
            'id' => (int) $p['id'], 'parent_id' => $p['parent_id'] ? (int) $p['parent_id'] : null,
            'slug' => $p['slug'], 'nome' => $p['nome'], 'cliente' => $p['cliente'],
            'tipo' => $p['tipo'], 'status' => $p['status'], 'descricao' => $p['descricao'], 'url' => $p['url'],
            'repositorio' => $p['repositorio'], 'stack' => $p['stack'],
            'servidor_id' => $p['servidor_id'] ? (int) $p['servidor_id'] : null,
            'servidor_nome' => $srv['nome'] ?? null, 'servidor_peso' => (int) $p['servidor_peso'],
            'usuarios_ativos' => $p['usuarios_ativos'] === null ? null : (int) $p['usuarios_ativos'],
            'responsavel' => $p['responsavel'], 'inicio' => $p['inicio'], 'observacao' => $p['observacao'],
            'atualizado_em' => $p['updated_at'],
            'itens' => array_map(function ($it) use ($cotacao, $hoje) {
                $total = self::totalItemBrl($it, $cotacao);
                return [
                    'id' => (int) $it['id'], 'natureza' => $it['natureza'], 'categoria' => $it['categoria'],
                    'descricao' => $it['descricao'], 'fornecedor' => $it['fornecedor'],
                    'valor_unitario' => $it['valor_unitario'] === null ? null : round((float) $it['valor_unitario'], 2),
                    'quantidade' => round((float) $it['quantidade'], 2), 'moeda' => $it['moeda'],
                    'recorrencia' => $it['recorrencia'], 'inicio' => $it['inicio'], 'fim' => $it['fim'],
                    'observacao' => $it['observacao'],
                    'total_brl' => $total === null ? null : $total / 100.0,
                    'mensal_brl' => ($m = self::mensal($it, $cotacao, $hoje)) === null ? null : $m / 100.0,
                    'vigente' => self::vigente($it, $hoje),
                ];
            }, $itens),
            'contas' => array_map(fn($c) => [
                'id' => (int) $c['id'], 'nome' => $c['nome'], 'identificador' => $c['identificador'], 'plano' => $c['plano'],
                'status' => $c['status'], 'moeda' => $c['moeda'],
                'receita_mensal' => $c['receita_mensal'] === null ? null : round((float) $c['receita_mensal'], 2),
                'usuarios' => $c['usuarios'] === null ? null : (int) $c['usuarios'],
                'peso_consumo' => round((float) $c['peso_consumo'], 2),
                'custo_direto' => $c['custo_direto'] === null ? null : round((float) $c['custo_direto'], 2),
                'inicio' => $c['inicio'], 'origem' => $c['origem'], 'sincronizado_em' => $c['sincronizado_em'],
                'observacao' => $c['observacao'],
                'metricas' => $c['metricas'] ? (json_decode((string) $c['metricas'], true) ?: null) : null,
                'calc' => $calc['contas'][(int) $c['id']] ?? null,
            ], $contas),
            'acessos' => array_map(fn($a) => [
                'id' => (int) $a['id'], 'rotulo' => $a['rotulo'], 'url' => $a['url'], 'usuario' => $a['usuario'],
                'tem_senha' => (bool) $a['tem_senha'], 'observacao' => $a['observacao'],
            ], $acessos),
            'evolucao' => array_map(fn($e) => ['mes' => $e['mes'], 'receita' => $e['receita'] / 100.0, 'custo' => $e['custo'] / 100.0], $evolucao),
            'calc' => $calc,
        ];
    }

    /* =================================================================
     * Escrita
     * ================================================================= */

    /** Conta dona da linha, se ela estiver entre as contas acessíveis; senão null. */
    public static function donoDa(string $tabela, int $id, array $contas): ?int
    {
        if (!in_array($tabela, ['projetos', 'projeto_itens', 'projeto_servidores', 'projeto_contas', 'projeto_acessos'], true)) return null;
        $pdo = Database::getConnection();
        [$in, $p] = self::inContas($contas);
        $st = $pdo->prepare("SELECT account_id FROM $tabela WHERE id = :id AND account_id IN $in AND deleted_at IS NULL");
        $st->execute(['id' => $id] + $p);
        $acc = $st->fetchColumn();
        return $acc === false ? null : (int) $acc;
    }

    /** Conta do projeto (para criar coisa dentro dele), ou null se não é das contas. */
    public static function contaDoProjeto(int $projetoId, array $contas): ?int
    {
        return self::donoDa('projetos', $projetoId, $contas);
    }

    /**
     * Mãe válida para o projeto: da mesma conta, de primeiro nível, não é ele
     * mesmo, e ele não tem filhos (só um nível). Devolve o id ou null.
     */
    private static function paiValido(?int $paiId, int $accountId, ?int $projetoId): ?int
    {
        if (!$paiId || $paiId === $projetoId) return null;
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT parent_id FROM projetos WHERE id = ? AND account_id = ? AND deleted_at IS NULL");
        $st->execute([$paiId, $accountId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['parent_id'] !== null) return null;
        if ($projetoId) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM projetos WHERE parent_id = ? AND account_id = ? AND deleted_at IS NULL");
            $st->execute([$projetoId, $accountId]);
            if ((int) $st->fetchColumn() > 0) return null;
        }
        return $paiId;
    }

    /** Servidor só vale se for da mesma conta do projeto. */
    private static function servidorDaConta(?int $servidorId, int $accountId): ?int
    {
        if (!$servidorId) return null;
        return self::donoDa('projeto_servidores', $servidorId, [$accountId]) === $accountId ? $servidorId : null;
    }

    private static function decimal(?int $centavos): ?string
    {
        if ($centavos === null) return null;
        return intdiv($centavos, 100) . '.' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Slug único na conta (acrescenta -2, -3... se já existir). */
    private static function slugLivre(\PDO $pdo, int $accountId, string $base, ?int $ignorarId = null): string
    {
        $slug = $base; $n = 2;
        $st = $pdo->prepare("SELECT COUNT(*) FROM projetos WHERE account_id = ? AND slug = ? AND deleted_at IS NULL AND id <> ?");
        while (true) {
            $st->execute([$accountId, $slug, $ignorarId ?? 0]);
            if ((int) $st->fetchColumn() === 0) return $slug;
            $slug = substr($base, 0, 75) . '-' . $n++;
        }
    }

    public static function criarProjeto(int $accountId, array $d, ?int $userId, ?string $slug = null): int
    {
        $pdo = Database::getConnection();
        $slug = self::slugLivre($pdo, $accountId, $slug ? self::slug($slug) : self::slug($d['nome']));
        $st = $pdo->prepare("INSERT INTO projetos (account_id, parent_id, slug, nome, cliente, tipo, status, descricao, url, repositorio, stack,
                                                   servidor_id, servidor_peso, usuarios_ativos, responsavel, inicio, observacao, created_by)
                             VALUES (:acc, :pai, :slug, :nome, :cliente, :tipo, :status, :descricao, :url, :repositorio, :stack,
                                     :srv, :peso, :usu, :resp, :inicio, :obs, :uid)");
        $st->execute(self::paramsProjeto($d, $accountId, null) + ['acc' => $accountId, 'slug' => $slug, 'uid' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function atualizarProjeto(int $id, array $contas, array $d): bool
    {
        $acc = self::donoDa('projetos', $id, $contas);
        if ($acc === null) return false;
        $st = Database::getConnection()->prepare("UPDATE projetos SET nome = :nome, cliente = :cliente, tipo = :tipo, status = :status,
                    descricao = :descricao, url = :url, repositorio = :repositorio, stack = :stack, servidor_id = :srv,
                    servidor_peso = :peso, usuarios_ativos = :usu, responsavel = :resp, inicio = :inicio, observacao = :obs,
                    parent_id = :pai
              WHERE id = :id AND account_id = :acc AND deleted_at IS NULL");
        $st->execute(self::paramsProjeto($d, $acc, $id) + ['id' => $id, 'acc' => $acc]);
        return true;
    }

    private static function paramsProjeto(array $d, int $accountId, ?int $projetoId): array
    {
        return [
            'pai' => self::paiValido($d['parent_id'] ?? null, $accountId, $projetoId),
            'nome' => $d['nome'], 'cliente' => $d['cliente'], 'tipo' => $d['tipo'], 'status' => $d['status'],
            'descricao' => $d['descricao'], 'url' => $d['url'], 'repositorio' => $d['repositorio'], 'stack' => $d['stack'],
            'srv' => self::servidorDaConta($d['servidor_id'], $accountId), 'peso' => $d['servidor_peso'],
            'usu' => $d['usuarios_ativos'], 'resp' => $d['responsavel'], 'inicio' => $d['inicio'], 'obs' => $d['observacao'],
        ];
    }

    /**
     * Exclusão lógica do projeto e do que é dele (itens, contas, acessos). Os
     * subprojetos não são apagados: sobem para o primeiro nível.
     */
    public static function excluirProjeto(int $id, array $contas): bool
    {
        $acc = self::donoDa('projetos', $id, $contas);
        if ($acc === null) return false;
        $pdo = Database::getConnection();
        $pdo->prepare("UPDATE projetos SET parent_id = NULL WHERE parent_id = ? AND account_id = ?")->execute([$id, $acc]);
        foreach (['projeto_itens', 'projeto_contas', 'projeto_acessos'] as $t) {
            $pdo->prepare("UPDATE $t SET deleted_at = NOW() WHERE projeto_id = ? AND account_id = ? AND deleted_at IS NULL")->execute([$id, $acc]);
        }
        $pdo->prepare("UPDATE projetos SET deleted_at = NOW() WHERE id = ? AND account_id = ? AND deleted_at IS NULL")->execute([$id, $acc]);
        return true;
    }

    /** Cria o item no projeto (da conta do projeto). Devolve o id, ou null se o projeto não é das contas. */
    public static function criarItem(int $projetoId, array $contas, array $d, ?int $userId): ?int
    {
        $acc = self::donoDa('projetos', $projetoId, $contas);
        if ($acc === null) return null;
        $pdo = Database::getConnection();
        $st = $pdo->prepare("INSERT INTO projeto_itens (account_id, projeto_id, natureza, categoria, descricao, fornecedor, valor_unitario,
                                                        quantidade, moeda, recorrencia, inicio, fim, observacao, created_by)
                             VALUES (:acc, :pid, :nat, :cat, :desc, :forn, :valor, :qtd, :moeda, :rec, :ini, :fim, :obs, :uid)");
        $st->execute(self::paramsItem($d) + ['acc' => $acc, 'pid' => $projetoId, 'uid' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function atualizarItem(int $id, array $contas, array $d): bool
    {
        $acc = self::donoDa('projeto_itens', $id, $contas);
        if ($acc === null) return false;
        $st = Database::getConnection()->prepare("UPDATE projeto_itens SET natureza = :nat, categoria = :cat, descricao = :desc,
                    fornecedor = :forn, valor_unitario = :valor, quantidade = :qtd, moeda = :moeda, recorrencia = :rec,
                    inicio = :ini, fim = :fim, observacao = :obs
              WHERE id = :id AND account_id = :acc AND deleted_at IS NULL");
        $st->execute(self::paramsItem($d) + ['id' => $id, 'acc' => $acc]);
        return true;
    }

    private static function paramsItem(array $d): array
    {
        return [
            'nat' => $d['natureza'], 'cat' => $d['categoria'], 'desc' => $d['descricao'], 'forn' => $d['fornecedor'],
            'valor' => self::decimal($d['valor_centavos']), 'qtd' => number_format($d['quantidade'], 2, '.', ''),
            'moeda' => $d['moeda'], 'rec' => $d['recorrencia'], 'ini' => $d['inicio'], 'fim' => $d['fim'], 'obs' => $d['observacao'],
        ];
    }

    public static function excluirItem(int $id, array $contas): bool
    {
        $acc = self::donoDa('projeto_itens', $id, $contas);
        if ($acc === null) return false;
        Database::getConnection()->prepare("UPDATE projeto_itens SET deleted_at = NOW() WHERE id = ? AND account_id = ?")->execute([$id, $acc]);
        return true;
    }

    public static function criarServidor(int $accountId, array $d, ?int $userId): int
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare("INSERT INTO projeto_servidores (account_id, nome, provedor, endereco, valor_mensal, moeda, observacao, created_by)
                             VALUES (:acc, :nome, :prov, :end, :valor, :moeda, :obs, :uid)");
        $st->execute(self::paramsServidor($d) + ['acc' => $accountId, 'uid' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function atualizarServidor(int $id, array $contas, array $d): bool
    {
        $acc = self::donoDa('projeto_servidores', $id, $contas);
        if ($acc === null) return false;
        $st = Database::getConnection()->prepare("UPDATE projeto_servidores SET nome = :nome, provedor = :prov, endereco = :end,
                    valor_mensal = :valor, moeda = :moeda, observacao = :obs WHERE id = :id AND account_id = :acc AND deleted_at IS NULL");
        $st->execute(self::paramsServidor($d) + ['id' => $id, 'acc' => $acc]);
        return true;
    }

    private static function paramsServidor(array $d): array
    {
        return ['nome' => $d['nome'], 'prov' => $d['provedor'], 'end' => $d['endereco'],
                'valor' => self::decimal($d['valor_centavos']), 'moeda' => $d['moeda'], 'obs' => $d['observacao']];
    }

    /** Exclusão lógica; os projetos que rodavam nele ficam sem servidor. */
    public static function excluirServidor(int $id, array $contas): bool
    {
        $acc = self::donoDa('projeto_servidores', $id, $contas);
        if ($acc === null) return false;
        $pdo = Database::getConnection();
        $pdo->prepare("UPDATE projetos SET servidor_id = NULL WHERE servidor_id = ? AND account_id = ?")->execute([$id, $acc]);
        $pdo->prepare("UPDATE projeto_servidores SET deleted_at = NOW() WHERE id = ? AND account_id = ?")->execute([$id, $acc]);
        return true;
    }

    /* =================================================================
     * Importação do dossiê (scripts/manutencao/semear_projetos.php)
     * ================================================================= */

    /**
     * Carrega um dossiê (JSON com "servidores" e "projetos") na conta, sem
     * duplicar e sem apagar o que alguém já preencheu na tela:
     *
     *  - servidor casa pelo nome; existente só ganha o que estiver vazio nele;
     *  - projeto casa pelo slug; existente só ganha campo que estiver vazio;
     *  - item casa por natureza + categoria + descrição dentro do projeto;
     *    existente fica como está;
     *  - "excluir_itens" tira item do projeto só se ele ainda está sem valor;
     *  - conta casa pelo nome e acesso pelo rótulo, dentro do projeto (o dossiê
     *    nunca traz senha: o campo é descartado);
     *  - "pai" (slug) liga o subprojeto à mãe, se ele ainda não tem mãe.
     *
     * @return list<string> o que foi (ou seria) feito, uma linha por ação
     */
    public static function importar(\PDO $pdo, int $accountId, array $dossie, bool $aplicar, ?int $userId = null): array
    {
        $log = [];
        $idsServidor = [];
        foreach ((array) ($dossie['servidores'] ?? []) as $s) {
            [$d, $erro] = self::validarServidor($s);
            if ($erro) { $log[] = "servidor ignorado ({$erro}): " . ($s['nome'] ?? '?'); continue; }
            $st = $pdo->prepare("SELECT * FROM projeto_servidores WHERE account_id = ? AND nome = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$accountId, $d['nome']]);
            $atual = $st->fetch(\PDO::FETCH_ASSOC);
            if ($atual) {
                $idsServidor[$d['nome']] = (int) $atual['id'];
                $novo = self::preencherVazios($atual, ['provedor' => $d['provedor'], 'endereco' => $d['endereco'],
                    'valor_mensal' => self::decimal($d['valor_centavos']), 'observacao' => $d['observacao']]);
                if ($novo) {
                    $log[] = "servidor {$d['nome']}: completa " . implode(', ', array_keys($novo));
                    if ($aplicar) self::atualizarCampos($pdo, 'projeto_servidores', (int) $atual['id'], $accountId, $novo);
                } else {
                    $log[] = "servidor {$d['nome']}: já existe, nada a completar";
                }
                continue;
            }
            $log[] = "servidor {$d['nome']}: será criado";
            $idsServidor[$d['nome']] = $aplicar ? self::criarServidor($accountId, $d, $userId) : -1;
        }

        foreach ((array) ($dossie['projetos'] ?? []) as $pr) {
            $pr['servidor_id'] = null;
            $nomeSrv = (string) ($pr['servidor'] ?? '');
            if ($nomeSrv !== '') {
                if (!isset($idsServidor[$nomeSrv])) {
                    $st = $pdo->prepare("SELECT id FROM projeto_servidores WHERE account_id = ? AND nome = ? AND deleted_at IS NULL LIMIT 1");
                    $st->execute([$accountId, $nomeSrv]);
                    $idsServidor[$nomeSrv] = (int) ($st->fetchColumn() ?: 0);
                }
                $pr['servidor_id'] = $idsServidor[$nomeSrv] > 0 ? $idsServidor[$nomeSrv] : null;
            }
            [$d, $erro] = self::validarProjeto($pr);
            if ($erro) { $log[] = "projeto ignorado ({$erro}): " . ($pr['nome'] ?? '?'); continue; }
            $slug = self::slug((string) ($pr['slug'] ?? $d['nome']));
            $st = $pdo->prepare("SELECT * FROM projetos WHERE account_id = ? AND slug = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$accountId, $slug]);
            $atual = $st->fetch(\PDO::FETCH_ASSOC);
            if ($atual) {
                $projId = (int) $atual['id'];
                $novo = self::preencherVazios($atual, [
                    'cliente' => $d['cliente'], 'descricao' => $d['descricao'], 'url' => $d['url'], 'repositorio' => $d['repositorio'],
                    'stack' => $d['stack'], 'servidor_id' => $d['servidor_id'], 'usuarios_ativos' => $d['usuarios_ativos'],
                    'responsavel' => $d['responsavel'], 'inicio' => $d['inicio'], 'observacao' => $d['observacao'],
                ]);
                $log[] = "projeto {$d['nome']}: " . ($novo ? 'completa ' . implode(', ', array_keys($novo)) : 'já existe, nada a completar');
                if ($novo && $aplicar) self::atualizarCampos($pdo, 'projetos', $projId, $accountId, $novo);
            } else {
                $log[] = "projeto {$d['nome']}: será criado";
                $projId = $aplicar ? self::criarProjeto($accountId, $d, $userId, $slug) : 0;
            }

            foreach ((array) ($pr['itens'] ?? []) as $it) {
                [$di, $erroI] = self::validarItem($it);
                if ($erroI) { $log[] = "   item ignorado ({$erroI}): " . ($it['descricao'] ?? '?'); continue; }
                if ($projId > 0) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM projeto_itens WHERE account_id = ? AND projeto_id = ? AND natureza = ?
                                            AND categoria = ? AND descricao = ? AND deleted_at IS NULL");
                    $st->execute([$accountId, $projId, $di['natureza'], $di['categoria'], $di['descricao']]);
                    if ((int) $st->fetchColumn() > 0) continue;
                }
                $valor = $di['valor_centavos'] === null ? 'a preencher' : number_format($di['valor_centavos'] / 100.0, 2, ',', '.') . ' ' . $di['moeda'];
                $log[] = "   + {$di['natureza']} {$di['categoria']}: {$di['descricao']} ({$valor})";
                if ($aplicar && $projId > 0) self::criarItem($projId, [$accountId], $di, $userId);
            }

            // Item que o dossiê manda tirar (ex.: "Assinaturas" trocado pelas contas):
            // só sai se ainda estiver sem valor, ou seja, ninguém preencheu.
            foreach ((array) ($pr['excluir_itens'] ?? []) as $ex) {
                if ($projId <= 0) continue;
                $st = $pdo->prepare("SELECT id FROM projeto_itens WHERE account_id = ? AND projeto_id = ? AND natureza = ? AND categoria = ?
                                        AND descricao = ? AND valor_unitario IS NULL AND deleted_at IS NULL");
                $st->execute([$accountId, $projId, $ex['natureza'] ?? '', $ex['categoria'] ?? '', $ex['descricao'] ?? '']);
                foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $idEx) {
                    $log[] = "   - item sem valor retirado: " . ($ex['descricao'] ?? '');
                    if ($aplicar) $pdo->prepare("UPDATE projeto_itens SET deleted_at = NOW() WHERE id = ? AND account_id = ?")->execute([$idEx, $accountId]);
                }
            }

            // Contas: casam pelo nome dentro do projeto; existente fica como está.
            foreach ((array) ($pr['contas'] ?? []) as $ct) {
                [$dc, $erroC] = Conta::validar($ct);
                if ($erroC) { $log[] = "   conta ignorada ({$erroC}): " . ($ct['nome'] ?? '?'); continue; }
                if ($projId > 0) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM projeto_contas WHERE account_id = ? AND projeto_id = ? AND nome = ? AND deleted_at IS NULL");
                    $st->execute([$accountId, $projId, $dc['nome']]);
                    if ((int) $st->fetchColumn() > 0) continue;
                }
                $log[] = "   + conta: {$dc['nome']}";
                if ($aplicar && $projId > 0) Conta::criar($projId, [$accountId], $dc, $userId);
            }

            // Acessos: casam pelo rótulo dentro do projeto. O dossiê nunca traz senha.
            foreach ((array) ($pr['acessos'] ?? []) as $ac) {
                unset($ac['senha']);
                [$da, $erroA] = Acesso::validar($ac);
                if ($erroA) { $log[] = "   acesso ignorado ({$erroA}): " . ($ac['rotulo'] ?? '?'); continue; }
                if ($projId > 0) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM projeto_acessos WHERE account_id = ? AND projeto_id = ? AND rotulo = ? AND deleted_at IS NULL");
                    $st->execute([$accountId, $projId, $da['rotulo']]);
                    if ((int) $st->fetchColumn() > 0) continue;
                }
                $log[] = "   + acesso: {$da['rotulo']}" . ($da['url'] ? " ({$da['url']})" : '');
                if ($aplicar && $projId > 0) Acesso::criar($projId, [$accountId], $da, $userId);
            }
        }

        // Segunda passada: subprojeto ganha a mãe ("pai": slug), se ainda não tem.
        foreach ((array) ($dossie['projetos'] ?? []) as $pr) {
            if (empty($pr['pai'])) continue;
            $slug = self::slug((string) ($pr['slug'] ?? $pr['nome'] ?? ''));
            $pai = self::slug((string) $pr['pai']);
            $st = $pdo->prepare("SELECT id, parent_id FROM projetos WHERE account_id = ? AND slug = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$accountId, $slug]);
            $filho = $st->fetch(\PDO::FETCH_ASSOC);
            $st->execute([$accountId, $pai]);
            $mae = $st->fetch(\PDO::FETCH_ASSOC);
            if ($aplicar && (!$filho || !$mae)) { $log[] = "subprojeto {$slug}: mãe {$pai} não encontrada"; continue; }
            if ($filho && $filho['parent_id'] !== null) continue;
            $log[] = "subprojeto {$slug} entra em {$pai}";
            if (!$aplicar) continue;
            $ok = self::paiValido((int) $mae['id'], $accountId, (int) $filho['id']);
            if ($ok) self::atualizarCampos($pdo, 'projetos', (int) $filho['id'], $accountId, ['parent_id' => $ok]);
            else $log[] = "   não ligado: {$pai} já é subprojeto, ou {$slug} tem subprojetos";
        }
        return $log;
    }

    /** Dos campos novos, só os que estão vazios no atual e têm valor no novo. */
    private static function preencherVazios(array $atual, array $novo): array
    {
        $out = [];
        foreach ($novo as $c => $v) {
            if ($v === null || $v === '') continue;
            if ($atual[$c] === null || $atual[$c] === '') $out[$c] = $v;
        }
        return $out;
    }

    private static function atualizarCampos(\PDO $pdo, string $tabela, int $id, int $accountId, array $campos): void
    {
        $permitidos = [
            'projetos' => ['cliente', 'descricao', 'url', 'repositorio', 'stack', 'servidor_id', 'usuarios_ativos', 'responsavel', 'inicio', 'observacao', 'parent_id'],
            'projeto_servidores' => ['provedor', 'endereco', 'valor_mensal', 'observacao'],
        ];
        $set = []; $par = ['id' => $id, 'acc' => $accountId];
        foreach ($campos as $c => $v) {
            if (!in_array($c, $permitidos[$tabela] ?? [], true)) continue;
            $set[] = "$c = :$c"; $par[$c] = $v;
        }
        if (!$set) return;
        $pdo->prepare("UPDATE $tabela SET " . implode(', ', $set) . " WHERE id = :id AND account_id = :acc")->execute($par);
    }
}
