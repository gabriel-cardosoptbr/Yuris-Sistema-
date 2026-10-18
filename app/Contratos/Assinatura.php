<?php
namespace App\Contratos;

use App\Core\Database;
use App\Master\Marca;

/**
 * O fluxo de quem assina, na página externa (/assinar). Sem conta no CRM.
 *
 *   1. abrir(token)       POST da página com o token do fragmento: confere o convite
 *                         (sem consumir) e abre uma sessão curta presa a ele.
 *   2. enviarCodigo()     código de 6 dígitos para o WhatsApp do convite (o contato
 *                         não se troca aqui). Intervalo mínimo entre envios.
 *   3. confirmarCodigo()  até N tentativas, validade de M minutos, uso único.
 *   4. identificar()      cadastro já ligado: confirma os dados; sem cadastro: nome e
 *                         CPF (dígitos conferidos). CPF diferente do esperado bloqueia.
 *   5. assinar()          aceite marcado + "Assinar contrato". Tudo conferido de novo
 *                         no servidor, numa transação, contra a versão e o hash atuais.
 *      recusar()          encerra o contrato como recusado.
 *
 * A autorização (código confirmado) vale para o participante, a versão e o hash do
 * documento daquela sessão. Versão nova, hash diferente ou convite revogado: a
 * sessão cai e é preciso abrir de novo, com novo código.
 *
 * Abrir o link ou ver o PDF NÃO é assinatura nem prova de leitura: são eventos
 * próprios ('acesso', 'documento_visualizado').
 */
final class Assinatura
{
    public const ACEITE_TEXTO  = 'Li o contrato apresentado e concordo em assiná-lo eletronicamente.';
    public const ACEITE_VERSAO = 'aceite-v1';
    public const METODO        = 'codigo_unico_whatsapp';
    public const SESSAO_MIN    = 30;
    public const MAX_CODIGOS_HORA = 8;

    /** @return array{cookie:string, csrf:string} */
    public static function abrir(string $token, ?string $ip, ?string $ua): array
    {
        $cv = Convite::peloToken($token);
        $erro = self::problemaDoConvite($cv);
        if ($erro) throw new \InvalidArgumentException($erro);

        $pdo = Database::getConnection();
        $v = $pdo->prepare('SELECT sha256 FROM crm_contrato_versoes WHERE id = ?');
        $v->execute([(int) $cv['versao_id']]);
        $sha = (string) $v->fetchColumn();

        $cookie = Cripto::token();
        $csrf = bin2hex(random_bytes(32));
        $pdo->prepare(
            'INSERT INTO crm_contrato_sessoes (sessao_hash, account_id, contrato_id, convite_id, participante_id, versao_id, sha256, csrf, criada_em, expira_em, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([Cripto::hashToken($cookie), (int) $cv['account_id'], (int) $cv['contrato_id'], (int) $cv['id'], (int) $cv['participante_id'],
            (int) $cv['versao_id'], $sha, $csrf, Tempo::agora(), Tempo::daquiA(self::SESSAO_MIN * 60), $ip, $ua]);
        Eventos::registrar((int) $cv['contrato_id'], 'acesso', ['convite_uuid' => $cv['uuid']],
            ['participante_id' => (int) $cv['participante_id'], 'versao_id' => (int) $cv['versao_id'], 'ip' => $ip, 'user_agent' => $ua]);
        return ['cookie' => $cookie, 'csrf' => $csrf];
    }

    /** Mensagem para quem abre um convite que não serve, ou null se serve. Sem dizer se existe cadastro. */
    private static function problemaDoConvite(?array $cv): ?string
    {
        if (!$cv) return 'Este link não é válido. Peça um novo link a quem enviou o contrato.';
        if ($cv['usado_em']) return 'Este link já foi usado para assinar.';
        if ($cv['revogado_em']) {
            return match ((string) $cv['revogado_motivo']) {
                'nova_versao' => 'O contrato foi atualizado e este link deixou de valer. Você vai receber um novo link para a versão nova.',
                'cancelado'   => 'Este contrato foi cancelado por quem o enviou.',
                'concluido'   => 'Este contrato já foi concluído.',
                'expirado'    => 'O prazo para assinar este contrato terminou.',
                default       => 'Este link deixou de valer. Peça um novo link a quem enviou o contrato.',
            };
        }
        if (Tempo::passou((string) $cv['expira_em'])) return 'Este link venceu. Peça um novo link a quem enviou o contrato.';
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT status, versao_atual_id, prazo_em FROM crm_contratos WHERE id = ?');
        $st->execute([(int) $cv['contrato_id']]);
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$c || !in_array($c['status'], Contrato::ABERTOS, true)) return 'Este contrato não está mais disponível para assinatura.';
        if ((int) $c['versao_atual_id'] !== (int) $cv['versao_id']) return 'O contrato foi atualizado e este link deixou de valer.';
        if ($c['prazo_em'] && Tempo::passou((string) $c['prazo_em'])) return 'O prazo para assinar este contrato terminou.';
        $p = $pdo->prepare('SELECT status FROM crm_contrato_participantes WHERE id = ?');
        $p->execute([(int) $cv['participante_id']]);
        $ps = (string) $p->fetchColumn();
        if ($ps === 'bloqueado') return 'A assinatura está em revisão por quem enviou o contrato. Aguarde o contato.';
        if ($ps !== 'convidado') return 'Este link não está mais disponível.';
        return null;
    }

    /** Sessão viva pelo cookie, conferindo de novo convite, versão e hash. */
    public static function sessao(?string $cookie): ?array
    {
        if ($cookie === null || !preg_match('/^[A-Za-z0-9_-]{43}$/', $cookie)) return null;
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT * FROM crm_contrato_sessoes WHERE sessao_hash = ?');
        $st->execute([Cripto::hashToken($cookie)]);
        $s = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$s || $s['encerrada_em'] || Tempo::passou((string) $s['expira_em'])) return null;
        return $s;
    }

    /** O convite ainda vale para esta sessão? Senão, a mensagem. */
    public static function validarSessao(array $s, bool $paraAssinar = true): ?string
    {
        $pdo = Database::getConnection();
        $cv = $pdo->prepare('SELECT * FROM crm_contrato_convites WHERE id = ?');
        $cv->execute([(int) $s['convite_id']]);
        $c = $cv->fetch(\PDO::FETCH_ASSOC);
        if (!$paraAssinar && $c && $c['usado_em']) return null;
        $erro = self::problemaDoConvite($c ?: null);
        if ($erro) return $erro;
        $v = $pdo->prepare('SELECT sha256 FROM crm_contrato_versoes WHERE id = ?');
        $v->execute([(int) $s['versao_id']]);
        if (!hash_equals((string) $v->fetchColumn(), (string) $s['sha256'])) return 'O documento mudou. Abra o link de novo.';
        return null;
    }

    /** O que a página mostra. Antes do código: só o mínimo para começar. */
    public static function estado(array $s): array
    {
        $pdo = Database::getConnection();
        $a = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
        $a->execute([(int) $s['account_id']]);
        $marca = Marca::daConta($a->fetch(\PDO::FETCH_ASSOC) ?: []);
        $c = $pdo->prepare('SELECT titulo, status, prazo_em FROM crm_contratos WHERE id = ?');
        $c->execute([(int) $s['contrato_id']]);
        $row = $c->fetch(\PDO::FETCH_ASSOC);
        $p = self::participante($s);
        $assinada = self::assinaturaDaSessao($s);
        $base = [
            'empresa' => ['nome' => $marca['nome'] ?? '', 'logo' => $marca['logo_url'] ?? null, 'cor' => $marca['paleta']['marca'] ?? '#015DFC'],
            'titulo' => $row['titulo'],
            'participante' => ['primeiro_nome' => explode(' ', trim((string) $p['nome']))[0], 'papel' => Contrato::PAPEIS[$p['papel']] ?? $p['papel']],
            'contato' => 'WhatsApp terminado em ' . $p['telefone_final'],
            'aceite' => ['texto' => self::ACEITE_TEXTO, 'versao' => self::ACEITE_VERSAO],
            'csrf' => $s['csrf'],
        ];
        if ($assinada) {
            return $base + ['etapa' => 'assinado', 'assinatura' => ['uuid' => $assinada['uuid'], 'assinado_em' => Tempo::local($assinada['assinado_em'], true),
                'contrato_concluido' => $row['status'] === 'concluido']];
        }
        $erro = self::validarSessao($s);
        if ($erro) return $base + ['etapa' => 'indisponivel', 'mensagem' => $erro];
        if (!$s['canal_confirmado_em']) {
            $ultimo = self::ultimoDesafio($s);
            return $base + ['etapa' => 'codigo', 'codigo_enviado' => (bool) $ultimo && !$ultimo['consumido_em'] && !$ultimo['invalidado_em'] && !Tempo::passou((string) $ultimo['expira_em'])];
        }
        $v = $pdo->prepare('SELECT numero, uuid, sha256, nome_arquivo FROM crm_contrato_versoes WHERE id = ?');
        $v->execute([(int) $s['versao_id']]);
        $ver = $v->fetch(\PDO::FETCH_ASSOC);
        $ident = $s['identificacao_json'] ? json_decode((string) $s['identificacao_json'], true) : null;
        return $base + [
            'etapa' => $ident ? 'documento' : 'identificacao',
            'participante_completo' => [
                'nome' => $p['nome'], 'cpf_esperado' => $p['cpf_mascara'], 'empresa' => $p['empresa'], 'cnpj' => $p['cnpj'], 'funcao' => $p['funcao'],
                'cadastro' => self::dadosDoCadastro($p),
            ],
            'identificacao' => $ident ? ['nome' => $ident['nome'], 'cpf' => $ident['cpf_mascara'], 'origem' => $ident['origem']] : null,
            'documento' => ['versao' => (int) $ver['numero'], 'versao_uuid' => $ver['uuid'], 'sha256' => $ver['sha256'], 'nome' => $ver['nome_arquivo']],
            'prazo' => Tempo::local($row['prazo_em']),
        ];
    }

    private static function participante(array $s): array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM crm_contrato_participantes WHERE id = ? AND account_id = ?');
        $st->execute([(int) $s['participante_id'], (int) $s['account_id']]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Cliente já ligado pela equipe ao participante: o nome e o CPF mascarado para confirmar. */
    private static function dadosDoCadastro(array $p): ?array
    {
        if (empty($p['cliente_id'])) return null;
        $st = Database::getConnection()->prepare('SELECT nome, cpf_cnpj FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
        $st->execute([(int) $p['cliente_id'], (int) $p['account_id']]);
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$c) return null;
        $cpf = Cpf::digitos((string) $c['cpf_cnpj']);
        return ['nome' => $c['nome'], 'cpf' => Cpf::valido($cpf) ? Cpf::mascarar($cpf) : null];
    }

    private static function ultimoDesafio(array $s): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM crm_contrato_desafios WHERE sessao_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([(int) $s['id']]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private static function assinaturaDaSessao(array $s): ?array
    {
        $st = Database::getConnection()->prepare('SELECT uuid, assinado_em FROM crm_contrato_assinaturas WHERE participante_id = ? AND versao_id = ?');
        $st->execute([(int) $s['participante_id'], (int) $s['versao_id']]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ── Código de uso único ───────────────────────────────────────────────

    /** @return array{enviado:bool, contato:string, aguardar?:int} */
    public static function enviarCodigo(array $s, ?string $ip): array
    {
        $erro = self::validarSessao($s);
        if ($erro) throw new \InvalidArgumentException($erro);
        if ($s['canal_confirmado_em']) throw new \InvalidArgumentException('O código já foi confirmado.');
        $cfg = Contrato::config((int) $s['account_id']);
        $pdo = Database::getConnection();

        $u = $pdo->prepare('SELECT criado_em FROM crm_contrato_desafios WHERE convite_id = ? ORDER BY id DESC LIMIT 1');
        $u->execute([(int) $s['convite_id']]);
        $ultimo = $u->fetchColumn();
        if ($ultimo) {
            $falta = $cfg['otp_intervalo_reenvio_seg'] - (time() - strtotime($ultimo . ' UTC'));
            if ($falta > 0) return ['enviado' => false, 'contato' => '', 'aguardar' => $falta];
        }
        $h = $pdo->prepare('SELECT COUNT(*) FROM crm_contrato_desafios WHERE convite_id = ? AND criado_em > ?');
        $h->execute([(int) $s['convite_id'], gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ((int) $h->fetchColumn() >= self::MAX_CODIGOS_HORA) throw new \InvalidArgumentException('Muitos códigos pedidos. Tente de novo daqui a uma hora.');

        $p = self::participante($s);
        $tel = (string) Cripto::decifrar($p['telefone_cifrado']);
        $contato = 'WhatsApp terminado em ' . $p['telefone_final'];
        $codigo = Cripto::codigoUnico(6);
        $uuid = Cripto::uuid();
        $pdo->prepare('UPDATE crm_contrato_desafios SET invalidado_em = ? WHERE sessao_id = ? AND consumido_em IS NULL AND invalidado_em IS NULL')
            ->execute([Tempo::agora(), (int) $s['id']]);
        $pdo->prepare(
            'INSERT INTO crm_contrato_desafios (uuid, account_id, sessao_id, convite_id, participante_id, versao_id, sha256, codigo_hmac, contato_mascarado, criado_em, expira_em, max_tentativas, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$uuid, (int) $s['account_id'], (int) $s['id'], (int) $s['convite_id'], (int) $s['participante_id'], (int) $s['versao_id'],
            (string) $s['sha256'], Cripto::hmac('otp:' . $uuid, $codigo), $contato, Tempo::agora(), Tempo::daquiA($cfg['otp_validade_min'] * 60),
            $cfg['otp_max_tentativas'], $ip]);
        $desafioId = (int) $pdo->lastInsertId();

        $titulo = self::tituloDoContrato((int) $s['contrato_id']);
        $texto = "Seu código para assinar o contrato \"{$titulo}\" é {$codigo}.\n"
            . "Ele vale por {$cfg['otp_validade_min']} minutos e só pode ser usado uma vez. Não compartilhe este código com ninguém.";
        $r = Entrega::whatsapp((int) $s['account_id'], $tel, $texto);
        unset($codigo, $texto);
        $pdo->prepare('UPDATE crm_contrato_desafios SET entrega_status = ? WHERE id = ?')->execute([$r['ok'] ? 'enviado' : 'falhou', $desafioId]);
        Eventos::registrar((int) $s['contrato_id'], $r['ok'] ? 'codigo_enviado' : 'codigo_nao_enviado',
            ['desafio_uuid' => $uuid, 'canal' => 'whatsapp', 'contato' => $contato] + ($r['ok'] ? [] : ['erro' => $r['erro'] ?? '']),
            ['participante_id' => (int) $s['participante_id'], 'versao_id' => (int) $s['versao_id'], 'ip' => $ip]);
        if (!$r['ok']) throw new \RuntimeException('Não conseguimos mandar o código agora. Tente de novo em instantes ou fale com quem enviou o contrato.');
        return ['enviado' => true, 'contato' => $contato];
    }

    /** @return array{ok:bool, restantes?:int} */
    public static function confirmarCodigo(array $s, string $codigo, ?string $ip): array
    {
        $erro = self::validarSessao($s);
        if ($erro) throw new \InvalidArgumentException($erro);
        if ($s['canal_confirmado_em']) return ['ok' => true];
        $codigo = preg_replace('/\D+/', '', $codigo) ?? '';
        $pdo = Database::getConnection();
        $d = self::ultimoDesafio($s);
        if (!$d || $d['consumido_em'] || $d['invalidado_em']) throw new \InvalidArgumentException('Peça um novo código.');
        if (Tempo::passou((string) $d['expira_em'])) throw new \InvalidArgumentException('O código venceu. Peça um novo código.');
        // Gasta a tentativa antes de comparar: chamadas simultâneas não ganham tentativas extras.
        $t = $pdo->prepare('UPDATE crm_contrato_desafios SET tentativas = tentativas + 1 WHERE id = ? AND tentativas < max_tentativas AND consumido_em IS NULL AND invalidado_em IS NULL');
        $t->execute([(int) $d['id']]);
        if ($t->rowCount() !== 1) throw new \InvalidArgumentException('Tentativas esgotadas. Peça um novo código.');
        $quem = ['participante_id' => (int) $s['participante_id'], 'versao_id' => (int) $s['versao_id'], 'ip' => $ip];

        $certo = strlen($codigo) === 6 && hash_equals((string) $d['codigo_hmac'], Cripto::hmac('otp:' . $d['uuid'], $codigo));
        if (!$certo) {
            $restantes = (int) $d['max_tentativas'] - ((int) $d['tentativas'] + 1);
            if ($restantes <= 0) $pdo->prepare('UPDATE crm_contrato_desafios SET invalidado_em = ? WHERE id = ?')->execute([Tempo::agora(), (int) $d['id']]);
            Eventos::registrar((int) $s['contrato_id'], 'codigo_incorreto', ['desafio_uuid' => $d['uuid'], 'restantes' => max(0, $restantes)], $quem);
            return ['ok' => false, 'restantes' => max(0, $restantes)];
        }
        $c = $pdo->prepare('UPDATE crm_contrato_desafios SET consumido_em = ? WHERE id = ? AND consumido_em IS NULL');
        $c->execute([Tempo::agora(), (int) $d['id']]);
        if ($c->rowCount() !== 1) throw new \InvalidArgumentException('Este código já foi usado. Peça um novo código.');
        $pdo->prepare('UPDATE crm_contrato_sessoes SET canal_confirmado_em = ?, desafio_id = ?, expira_em = ? WHERE id = ?')
            ->execute([Tempo::agora(), (int) $d['id'], Tempo::daquiA(self::SESSAO_MIN * 60), (int) $s['id']]);
        Eventos::registrar((int) $s['contrato_id'], 'codigo_confirmado', ['desafio_uuid' => $d['uuid'], 'contato' => $d['contato_mascarado']], $quem);
        return ['ok' => true];
    }

    // ── Identificação ─────────────────────────────────────────────────────

    /**
     * $confirmarCadastro: a pessoa confirmou os dados do cadastro já ligado pela equipe.
     * Senão, declara nome e CPF. CPF diferente do esperado bloqueia para revisão.
     * @return array{ok:bool, bloqueado?:bool, mensagem?:string}
     */
    public static function identificar(array $s, string $nome, string $cpf, bool $confirmarCadastro, ?string $ip): array
    {
        $erro = self::validarSessao($s);
        if ($erro) throw new \InvalidArgumentException($erro);
        if (!$s['canal_confirmado_em']) throw new \InvalidArgumentException('Confirme o código antes.');
        $p = self::participante($s);
        $quem = ['participante_id' => (int) $s['participante_id'], 'versao_id' => (int) $s['versao_id'], 'ip' => $ip];
        $pdo = Database::getConnection();

        $origem = 'autodeclarado';
        if ($confirmarCadastro) {
            $cad = self::dadosDoCadastro($p);
            if (!$cad || !$cad['cpf']) throw new \InvalidArgumentException('Não há cadastro para confirmar. Preencha seus dados.');
            $st = $pdo->prepare('SELECT nome, cpf_cnpj FROM clientes WHERE id = ? AND account_id = ?');
            $st->execute([(int) $p['cliente_id'], (int) $p['account_id']]);
            $cli = $st->fetch(\PDO::FETCH_ASSOC);
            $nome = (string) $cli['nome'];
            $cpf = Cpf::digitos((string) $cli['cpf_cnpj']);
            $origem = 'cadastro_confirmado';
        }
        $nome = trim(preg_replace('/\s+/', ' ', $nome) ?? '');
        if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) throw new \InvalidArgumentException('Informe seu nome completo.');
        $cpf = Cpf::digitos($cpf);
        if (!Cpf::valido($cpf)) throw new \InvalidArgumentException('CPF inválido. Confira os números.');
        $hmac = Cpf::hmac($cpf);

        if (!empty($p['cpf_hmac']) && !hash_equals((string) $p['cpf_hmac'], $hmac)) {
            $pdo->prepare("UPDATE crm_contrato_participantes SET status = 'bloqueado', bloqueio_motivo = ?, atualizado_em = ? WHERE id = ? AND status = 'convidado'")
                ->execute(['O CPF informado na assinatura é diferente do CPF esperado (' . Cpf::mascarar($cpf) . ').', Tempo::agora(), (int) $p['id']]);
            Convite::revogarDoParticipante((int) $p['id'], 'cpf_divergente');
            $pdo->prepare('UPDATE crm_contrato_sessoes SET encerrada_em = ? WHERE id = ?')->execute([Tempo::agora(), (int) $s['id']]);
            Eventos::registrar((int) $s['contrato_id'], 'cpf_divergente', ['cpf_informado' => Cpf::mascarar($cpf), 'cpf_esperado' => $p['cpf_mascara']], $quem);
            return ['ok' => false, 'bloqueado' => true, 'mensagem' => 'Os dados informados não conferem com os do convite. A assinatura ficou em revisão e quem enviou o contrato vai entrar em contato.'];
        }

        // Possível cadastro com o mesmo CPF na conta: só sinaliza para a equipe; nada é ligado ou mesclado aqui.
        $possivel = null;
        if (empty($p['cliente_id'])) {
            $cl = $pdo->prepare("SELECT id FROM clientes WHERE account_id = ? AND deleted_at IS NULL AND REGEXP_REPLACE(COALESCE(cpf_cnpj,''), '[^0-9]', '') = ? LIMIT 1");
            $cl->execute([(int) $p['account_id'], $cpf]);
            $possivel = $cl->fetchColumn() ?: null;
        }
        $ident = [
            'nome' => mb_substr($nome, 0, 160), 'cpf_cifrado' => Cripto::cifrar($cpf), 'cpf_hmac' => $hmac, 'cpf_mascara' => Cpf::mascarar($cpf),
            'origem' => $origem, 'cliente_id' => $p['cliente_id'] ? (int) $p['cliente_id'] : null,
            'cpf_conferido_com_convite' => !empty($p['cpf_hmac']),
        ];
        $pdo->prepare('UPDATE crm_contrato_sessoes SET identificacao_json = ? WHERE id = ?')->execute([Cripto::canonico($ident), (int) $s['id']]);
        Eventos::registrar((int) $s['contrato_id'], 'identificacao', ['origem' => $origem, 'cpf' => Cpf::mascarar($cpf)]
            + ($possivel ? ['possivel_cadastro_cliente_id' => (int) $possivel] : []), $quem);
        return ['ok' => true];
    }

    public static function marcarVisualizacao(array $s, ?string $ip): void
    {
        $st = Database::getConnection()->prepare("SELECT COUNT(*) FROM crm_contrato_eventos WHERE contrato_id = ? AND participante_id = ? AND versao_id = ? AND tipo = 'documento_visualizado' AND dados_json LIKE ?");
        $st->execute([(int) $s['contrato_id'], (int) $s['participante_id'], (int) $s['versao_id'], '%"sessao":' . (int) $s['id'] . '}%']);
        if ((int) $st->fetchColumn() > 0) return;
        Eventos::registrar((int) $s['contrato_id'], 'documento_visualizado', ['sessao' => (int) $s['id'], 'observacao' => 'Arquivo entregue ao navegador; não prova leitura integral.'],
            ['participante_id' => (int) $s['participante_id'], 'versao_id' => (int) $s['versao_id'], 'ip' => $ip]);
    }

    // ── Assinar e recusar ─────────────────────────────────────────────────

    /** @return array{uuid:string, assinado_em:string, contrato:string, nova:bool} */
    public static function assinar(array $s, bool $aceite, string $idempotencia, ?string $ip, ?string $ua): array
    {
        if (!$aceite) throw new \InvalidArgumentException('Marque que leu o contrato e concorda em assiná-lo.');
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $idempotencia)) throw new \InvalidArgumentException('Pedido inválido. Recarregue a página.');
        $idem = hash('sha256', 'sessao:' . $s['id'] . ':' . $idempotencia);
        $pdo = Database::getConnection();

        $ja = $pdo->prepare('SELECT uuid, assinado_em, idempotencia FROM crm_contrato_assinaturas WHERE participante_id = ? AND versao_id = ?');
        $ja->execute([(int) $s['participante_id'], (int) $s['versao_id']]);
        if ($x = $ja->fetch(\PDO::FETCH_ASSOC)) {
            return ['uuid' => $x['uuid'], 'assinado_em' => Tempo::local($x['assinado_em'], true), 'contrato' => self::statusDoContrato((int) $s['contrato_id']), 'nova' => false];
        }

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM crm_contratos WHERE id = ? AND account_id = ? FOR UPDATE');
            $st->execute([(int) $s['contrato_id'], (int) $s['account_id']]);
            $c = $st->fetch(\PDO::FETCH_ASSOC);
            $ps = $pdo->prepare('SELECT * FROM crm_contrato_participantes WHERE id = ? AND account_id = ? FOR UPDATE');
            $ps->execute([(int) $s['participante_id'], (int) $s['account_id']]);
            $p = $ps->fetch(\PDO::FETCH_ASSOC);
            $ss = $pdo->prepare('SELECT * FROM crm_contrato_sessoes WHERE id = ? FOR UPDATE');
            $ss->execute([(int) $s['id']]);
            $s = $ss->fetch(\PDO::FETCH_ASSOC);

            // Assinatura que entrou enquanto esperávamos a trava (clique duplo, duas abas).
            $ja->execute([(int) $s['participante_id'], (int) $s['versao_id']]);
            if ($x = $ja->fetch(\PDO::FETCH_ASSOC)) {
                $pdo->commit();
                return ['uuid' => $x['uuid'], 'assinado_em' => Tempo::local($x['assinado_em'], true), 'contrato' => self::statusDoContrato((int) $s['contrato_id']), 'nova' => false];
            }
            if (!$s || $s['encerrada_em'] || Tempo::passou((string) $s['expira_em'])) throw new \InvalidArgumentException('Sua sessão terminou. Abra o link de novo.');
            $erro = self::validarSessao($s);
            if ($erro) throw new \InvalidArgumentException($erro);
            if (!$c || !in_array($c['status'], Contrato::ABERTOS, true)) throw new \InvalidArgumentException('Este contrato não está mais disponível para assinatura.');
            if (!$s['canal_confirmado_em'] || !$s['desafio_id']) throw new \InvalidArgumentException('Confirme o código antes de assinar.');
            if (!$s['identificacao_json']) throw new \InvalidArgumentException('Confira seus dados antes de assinar.');
            if (!$p || $p['status'] !== 'convidado') throw new \InvalidArgumentException('Esta assinatura não está liberada.');

            $d = $pdo->prepare('SELECT * FROM crm_contrato_desafios WHERE id = ?');
            $d->execute([(int) $s['desafio_id']]);
            $des = $d->fetch(\PDO::FETCH_ASSOC);
            $ver = $pdo->prepare('SELECT * FROM crm_contrato_versoes WHERE id = ? AND contrato_id = ?');
            $ver->execute([(int) $s['versao_id'], (int) $c['id']]);
            $v = $ver->fetch(\PDO::FETCH_ASSOC);
            // A autorização vale para este participante, esta versão e este hash. Qualquer diferença: novo código.
            if (!$des || !$des['consumido_em'] || (int) $des['participante_id'] !== (int) $p['id'] || (int) $des['versao_id'] !== (int) $v['id']
                || !hash_equals((string) $des['sha256'], (string) $v['sha256']) || (int) $c['versao_atual_id'] !== (int) $v['id'] || $v['status'] !== 'aberta') {
                throw new \InvalidArgumentException('A confirmação não vale para esta versão do documento. Abra o link de novo.');
            }
            $cv = $pdo->prepare('SELECT * FROM crm_contrato_convites WHERE id = ? FOR UPDATE');
            $cv->execute([(int) $s['convite_id']]);
            $conv = $cv->fetch(\PDO::FETCH_ASSOC);
            if (!$conv || $conv['revogado_em'] || $conv['usado_em']) throw new \InvalidArgumentException('Este link deixou de valer.');

            $ident = json_decode((string) $s['identificacao_json'], true);
            $uuid = Cripto::uuid();
            $agora = Tempo::agoraMs();
            $identFoto = $ident + [
                'papel' => $p['papel'], 'empresa' => $p['empresa'], 'cnpj' => $p['cnpj'], 'funcao' => $p['funcao'],
                'nome_no_convite' => $p['nome'],
            ];
            $evidencia = [
                'formato' => 'yuris-contratos-evidencia/1',
                'tipo_assinatura' => 'Assinatura eletrônica com confirmação por código de uso único enviado ao WhatsApp do convite. Não é assinatura eletrônica qualificada (ICP-Brasil).',
                'assinatura_uuid' => $uuid,
                'contrato' => ['uuid' => $c['uuid'], 'codigo_verificacao' => $c['codigo_verificacao']],
                'versao' => ['uuid' => $v['uuid'], 'numero' => (int) $v['numero'], 'sha256' => $v['sha256']],
                'participante' => ['uuid' => $p['uuid'], 'papel' => $p['papel']],
                'signatario' => [
                    'nome' => $ident['nome'], 'cpf_mascara' => $ident['cpf_mascara'], 'cpf_hmac' => $ident['cpf_hmac'],
                    'origem' => $ident['origem'], 'cpf_conferido_com_convite' => $ident['cpf_conferido_com_convite'],
                    'vinculo_cadastro_cliente_id' => $ident['cliente_id'],
                    'observacao' => $ident['origem'] === 'autodeclarado'
                        ? 'Nome e CPF declarados pelo signatário. O CPF teve só os dígitos conferidos; isso não comprova titularidade nem situação na Receita Federal.'
                        : 'Dados do cadastro do cliente, confirmados pelo signatário.',
                ],
                'representacao' => $p['papel'] === 'representante' ? [
                    'empresa' => $p['empresa'], 'cnpj' => $p['cnpj'], 'funcao' => $p['funcao'],
                    'observacao' => 'A identificação do representante não comprova, sozinha, seus poderes de representação.',
                ] : null,
                'confirmacao' => [
                    'metodo' => self::METODO, 'canal' => 'whatsapp', 'contato' => $des['contato_mascarado'], 'desafio_uuid' => $des['uuid'],
                    'convite_uuid' => $conv['uuid'], 'observacao' => 'O código comprova acesso ao WhatsApp do convite, não a titularidade do CPF.',
                ],
                'aceite' => ['texto' => self::ACEITE_TEXTO, 'versao' => self::ACEITE_VERSAO, 'marcado' => true],
                'instante_utc' => $agora,
                'instante_origem' => 'relógio do servidor (sem carimbo do tempo de terceiro)',
                'rede' => ['ip' => $ip, 'navegador' => $ua],
            ];
            $evJson = Cripto::canonico($evidencia);
            $pdo->prepare(
                'INSERT INTO crm_contrato_assinaturas (uuid, account_id, contrato_id, versao_id, participante_id, convite_id, desafio_id, sha256_documento, assinado_em,
                                                       ip, user_agent, metodo, contato_mascarado, aceite_texto, aceite_versao, identificacao_json, evidencia_json, evidencia_sha256, idempotencia)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$uuid, (int) $c['account_id'], (int) $c['id'], (int) $v['id'], (int) $p['id'], (int) $conv['id'], (int) $des['id'], $v['sha256'], $agora,
                $ip, $ua, self::METODO, $des['contato_mascarado'], self::ACEITE_TEXTO, self::ACEITE_VERSAO, Cripto::canonico($identFoto), $evJson, hash('sha256', $evJson), $idem]);
            $pdo->prepare('UPDATE crm_contrato_convites SET usado_em = ? WHERE id = ?')->execute([Tempo::agora(), (int) $conv['id']]);
            $pdo->prepare("UPDATE crm_contrato_participantes SET status = 'assinou', atualizado_em = ? WHERE id = ?")->execute([Tempo::agora(), (int) $p['id']]);
            Eventos::registrar((int) $c['id'], 'assinado', ['assinatura_uuid' => $uuid, 'sha256' => $v['sha256'], 'evidencia_sha256' => hash('sha256', $evJson)],
                ['participante_id' => (int) $p['id'], 'versao_id' => (int) $v['id'], 'ip' => $ip, 'user_agent' => $ua]);
            $novo = Contrato::recalcular((int) $c['id']);
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Corrida perdida para a restrição de unicidade: devolve a assinatura que ficou.
            $ja->execute([(int) $s['participante_id'], (int) $s['versao_id']]);
            if ($x = $ja->fetch(\PDO::FETCH_ASSOC)) {
                return ['uuid' => $x['uuid'], 'assinado_em' => Tempo::local($x['assinado_em'], true), 'contrato' => self::statusDoContrato((int) $s['contrato_id']), 'nova' => false];
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($novo === 'concluido') {
            try { Finalizacao::processar((int) $c['id']); } catch (\Throwable $e) { error_log('[contratos] finalização adiada do contrato ' . (int) $c['id'] . ': ' . $e->getMessage()); }
        }
        return ['uuid' => $uuid, 'assinado_em' => Tempo::local($agora, true), 'contrato' => $novo, 'nova' => true];
    }

    public static function recusar(array $s, string $motivo, ?string $ip, ?string $ua): void
    {
        $erro = self::validarSessao($s);
        if ($erro) throw new \InvalidArgumentException($erro);
        if (!$s['canal_confirmado_em']) throw new \InvalidArgumentException('Confirme o código antes.');
        $motivo = trim($motivo);
        if ($motivo === '') throw new \InvalidArgumentException('Conte por que você não vai assinar.');
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM crm_contratos WHERE id = ? FOR UPDATE');
            $st->execute([(int) $s['contrato_id']]);
            $c = $st->fetch(\PDO::FETCH_ASSOC);
            if (!in_array($c['status'], Contrato::ABERTOS, true)) throw new \InvalidArgumentException('Este contrato não está mais disponível.');
            $agora = Tempo::agora();
            $pdo->prepare("UPDATE crm_contrato_participantes SET status = 'recusou', atualizado_em = ? WHERE id = ?")->execute([$agora, (int) $s['participante_id']]);
            $pdo->prepare("UPDATE crm_contratos SET status = 'recusado', encerrado_em = ?, motivo_encerramento = ?, atualizado_em = ? WHERE id = ?")
                ->execute([$agora, 'Recusado por um participante: ' . mb_substr($motivo, 0, 400), $agora, (int) $c['id']]);
            $pdo->prepare("UPDATE crm_contrato_versoes SET status = 'encerrada' WHERE id = ? AND status = 'aberta'")->execute([(int) $c['versao_atual_id']]);
            Convite::revogarDoContrato((int) $c['id'], 'recusado');
            $pdo->prepare('UPDATE crm_contrato_sessoes SET encerrada_em = ? WHERE contrato_id = ? AND encerrada_em IS NULL')->execute([$agora, (int) $c['id']]);
            Eventos::registrar((int) $c['id'], 'recusado', ['motivo' => mb_substr($motivo, 0, 500)],
                ['participante_id' => (int) $s['participante_id'], 'versao_id' => (int) $s['versao_id'], 'ip' => $ip, 'user_agent' => $ua]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Bytes do PDF da versão desta sessão, com o hash conferido. */
    public static function pdf(array $s): ?array
    {
        $st = Database::getConnection()->prepare(
            'SELECT a.caminho, a.sha256, v.nome_arquivo FROM crm_contrato_versoes v JOIN crm_contrato_arquivos a ON a.id = v.arquivo_id WHERE v.id = ? AND v.account_id = ?'
        );
        $st->execute([(int) $s['versao_id'], (int) $s['account_id']]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r || !hash_equals((string) $r['sha256'], (string) $s['sha256'])) return null;
        $b = Armazenamento::ler((string) $r['caminho'], (string) $r['sha256']);
        return $b === null ? null : ['bytes' => $b, 'nome' => $r['nome_arquivo']];
    }

    private static function tituloDoContrato(int $id): string
    {
        $st = Database::getConnection()->prepare('SELECT titulo FROM crm_contratos WHERE id = ?');
        $st->execute([$id]);
        return (string) $st->fetchColumn();
    }

    private static function statusDoContrato(int $id): string
    {
        $st = Database::getConnection()->prepare('SELECT status FROM crm_contratos WHERE id = ?');
        $st->execute([$id]);
        return (string) $st->fetchColumn();
    }
}
