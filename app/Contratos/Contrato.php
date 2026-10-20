<?php
namespace App\Contratos;

use App\Core\Database;
use App\Master\Marca;

/**
 * O contrato pelo lado da equipe: criar, anexar o PDF, montar os participantes,
 * fechar a versão e mandar os convites, gerar novo link, liberar um bloqueio,
 * substituir por nova versão, cancelar, listar e a central da conta.
 *
 * Toda leitura e escrita filtra account_id. Id vindo da tela nunca basta sozinho.
 * Erro de quem usa (campo faltando, situação que não permite) é
 * \InvalidArgumentException, com a mensagem pronta para a tela.
 */
final class Contrato
{
    public const STATUS = [
        'rascunho'   => 'Rascunho',
        'aguardando' => 'Aguardando assinaturas',
        'parcial'    => 'Parcialmente assinado',
        'concluido'  => 'Concluído',
        'recusado'   => 'Recusado',
        'expirado'   => 'Expirado',
        'cancelado'  => 'Cancelado',
    ];
    public const PAPEIS = [
        'contratante'   => 'Contratante',
        'contratado'    => 'Contratado',
        'representante' => 'Representante da empresa',
        'testemunha'    => 'Testemunha',
    ];
    public const STATUS_PARTICIPANTE = [
        'pendente'  => 'Aguardando envio',
        'convidado' => 'Convidado',
        'assinou'   => 'Assinou',
        'recusou'   => 'Recusou',
        'bloqueado' => 'Bloqueado para revisão',
        'cancelado' => 'Removido',
        'expirado'  => 'Expirado',
    ];
    /** Situações em que ainda se pode assinar. */
    public const ABERTOS = ['aguardando', 'parcial'];

    // ── Configuração da conta ─────────────────────────────────────────────

    /** @return array{otp_validade_min:int, otp_max_tentativas:int, otp_intervalo_reenvio_seg:int, convite_validade_dias:int} */
    public static function config(int $accountId): array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM crm_contrato_config WHERE account_id = ?');
        $st->execute([$accountId]);
        $c = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'otp_validade_min'          => (int) ($c['otp_validade_min'] ?? 5),
            'otp_max_tentativas'        => (int) ($c['otp_max_tentativas'] ?? 5),
            'otp_intervalo_reenvio_seg' => (int) ($c['otp_intervalo_reenvio_seg'] ?? 60),
            'convite_validade_dias'     => (int) ($c['convite_validade_dias'] ?? 15),
        ];
    }

    public static function salvarConfig(int $accountId, int $userId, array $d): array
    {
        $v = [
            'otp_validade_min'          => self::limitar($d['otp_validade_min'] ?? 5, 2, 30, 'A validade do código vai de 2 a 30 minutos.'),
            'otp_max_tentativas'        => self::limitar($d['otp_max_tentativas'] ?? 5, 3, 10, 'As tentativas vão de 3 a 10.'),
            'otp_intervalo_reenvio_seg' => self::limitar($d['otp_intervalo_reenvio_seg'] ?? 60, 30, 600, 'O intervalo de reenvio vai de 30 a 600 segundos.'),
            'convite_validade_dias'     => self::limitar($d['convite_validade_dias'] ?? 15, 1, 90, 'A validade do convite vai de 1 a 90 dias.'),
        ];
        Database::getConnection()->prepare(
            'INSERT INTO crm_contrato_config (account_id, otp_validade_min, otp_max_tentativas, otp_intervalo_reenvio_seg, convite_validade_dias, atualizado_em, atualizado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE otp_validade_min = VALUES(otp_validade_min), otp_max_tentativas = VALUES(otp_max_tentativas),
               otp_intervalo_reenvio_seg = VALUES(otp_intervalo_reenvio_seg), convite_validade_dias = VALUES(convite_validade_dias),
               atualizado_em = VALUES(atualizado_em), atualizado_por = VALUES(atualizado_por)'
        )->execute([$accountId, $v['otp_validade_min'], $v['otp_max_tentativas'], $v['otp_intervalo_reenvio_seg'], $v['convite_validade_dias'], Tempo::agora(), $userId]);
        return $v;
    }

    private static function limitar(mixed $v, int $min, int $max, string $msg): int
    {
        $n = filter_var($v, FILTER_VALIDATE_INT);
        if ($n === false || $n < $min || $n > $max) throw new \InvalidArgumentException($msg);
        return $n;
    }

    // ── Criar e editar ────────────────────────────────────────────────────

    public static function criar(int $accountId, int $userId, array $d): int
    {
        $pdo = Database::getConnection();
        $campos = self::validarCabecalho($accountId, $d);
        $uuid = Cripto::uuid();
        $agora = Tempo::agora();
        for ($i = 0; $i < 5; $i++) {
            try {
                $pdo->prepare(
                    'INSERT INTO crm_contratos (uuid, account_id, codigo_verificacao, titulo, responsavel_id, cliente_id, card_id, prazo_em, criado_por, criado_em, atualizado_em)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$uuid, $accountId, Cripto::codigoVerificacao(), $campos['titulo'], $campos['responsavel_id'] ?? $userId,
                    $campos['cliente_id'], $campos['card_id'], $campos['prazo_em'], $userId, $agora, $agora]);
                break;
            } catch (\PDOException $e) {
                if ($i === 4 || !str_contains($e->getMessage(), 'uq_contrato_codigo')) throw $e;
            }
        }
        $id = (int) $pdo->lastInsertId();
        Eventos::registrar($id, 'criado', ['titulo' => $campos['titulo']], ['user_id' => $userId]);
        return $id;
    }

    public static function atualizar(int $accountId, int $userId, int $id, array $d): void
    {
        $c = self::doContrato($accountId, $id);
        if (in_array($c['status'], ['concluido', 'cancelado', 'recusado', 'expirado'], true)) {
            throw new \InvalidArgumentException('Contrato encerrado não muda.');
        }
        $campos = self::validarCabecalho($accountId, $d + ['titulo' => $c['titulo']]);
        Database::getConnection()->prepare(
            'UPDATE crm_contratos SET titulo = ?, responsavel_id = ?, cliente_id = ?, card_id = ?, prazo_em = ?, atualizado_em = ?
              WHERE id = ? AND account_id = ?'
        )->execute([$campos['titulo'], $campos['responsavel_id'] ?? $c['responsavel_id'], $campos['cliente_id'], $campos['card_id'],
            $campos['prazo_em'], Tempo::agora(), $id, $accountId]);
        Eventos::registrar($id, 'editado', ['titulo' => $campos['titulo'], 'prazo_em' => $campos['prazo_em']], ['user_id' => $userId]);
    }

    private static function validarCabecalho(int $accountId, array $d): array
    {
        $pdo = Database::getConnection();
        $titulo = trim((string) ($d['titulo'] ?? ''));
        if ($titulo === '' || mb_strlen($titulo) > 200) throw new \InvalidArgumentException('Informe o título do contrato (até 200 caracteres).');

        $resp = !empty($d['responsavel_id']) ? (int) $d['responsavel_id'] : null;
        if ($resp) {
            $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL");
            $st->execute([$resp, $accountId]);
            if (!$st->fetchColumn()) throw new \InvalidArgumentException('Responsável não encontrado nesta conta.');
        }
        $cliente = !empty($d['cliente_id']) ? (int) $d['cliente_id'] : null;
        if ($cliente) {
            $st = $pdo->prepare('SELECT id FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
            $st->execute([$cliente, $accountId]);
            if (!$st->fetchColumn()) throw new \InvalidArgumentException('Cliente não encontrado nesta conta.');
        }
        $card = !empty($d['card_id']) ? (int) $d['card_id'] : null;
        if ($card) {
            $st = $pdo->prepare('SELECT id FROM cards WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
            $st->execute([$card, $accountId]);
            if (!$st->fetchColumn()) throw new \InvalidArgumentException('Negociação não encontrada nesta conta.');
        }
        $prazo = null;
        if (!empty($d['prazo'])) {
            $prazo = Tempo::fimDoDiaLocalEmUtc((string) $d['prazo']);
            if ($prazo === null) throw new \InvalidArgumentException('Prazo inválido.');
            if (Tempo::passou($prazo)) throw new \InvalidArgumentException('O prazo para assinatura já passou.');
        }
        return ['titulo' => $titulo, 'responsavel_id' => $resp, 'cliente_id' => $cliente, 'card_id' => $card, 'prazo_em' => $prazo];
    }

    /**
     * Liga (ou desliga, com 0) o contrato a um cliente. Vale em qualquer situação: o
     * cliente é cadastro do CRM, não faz parte do documento assinado.
     */
    public static function vincularCliente(int $accountId, int $userId, int $id, int $clienteId): void
    {
        $c = self::doContrato($accountId, $id);
        $pdo = Database::getConnection();
        if ($clienteId) {
            $st = $pdo->prepare('SELECT nome FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
            $st->execute([$clienteId, $accountId]);
            $nome = $st->fetchColumn();
            if (!$nome) throw new \InvalidArgumentException('Cliente não encontrado nesta conta.');
        }
        $pdo->prepare('UPDATE crm_contratos SET cliente_id = ?, atualizado_em = ? WHERE id = ? AND account_id = ?')
            ->execute([$clienteId ?: null, Tempo::agora(), $id, $accountId]);
        Eventos::registrar($id, 'cliente_vinculado', ['cliente_id' => $clienteId ?: null, 'anterior' => $c['cliente_id'] ? (int) $c['cliente_id'] : null], ['user_id' => $userId]);
    }

    // ── Documento ─────────────────────────────────────────────────────────

    /**
     * Anexa o PDF. Sem versão aberta para assinatura, ele vira (ou troca) o rascunho
     * da próxima versão. Com versão aberta, também vira rascunho: a versão aberta só
     * é substituída quando a nova for fechada e enviada.
     */
    public static function anexarPdf(int $accountId, int $userId, int $id, string $bytes, string $nomeArquivo): array
    {
        $c = self::doContrato($accountId, $id);
        if (in_array($c['status'], ['concluido', 'cancelado', 'recusado', 'expirado'], true)) {
            throw new \InvalidArgumentException('Contrato encerrado não recebe documento novo.');
        }
        if (strlen($bytes) > Armazenamento::MAX_PDF_BYTES) throw new \InvalidArgumentException('O PDF passa de 20 MB.');
        if (!Armazenamento::ehPdf($bytes)) throw new \InvalidArgumentException('O arquivo não é um PDF.');
        if (preg_match('/\/Encrypt\s/', $bytes)) throw new \InvalidArgumentException('O PDF tem senha ou proteção. Envie uma versão sem proteção.');

        $pdo = Database::getConnection();
        $nome = mb_substr(trim(basename(str_replace('\\', '/', $nomeArquivo))) ?: 'contrato.pdf', 0, 200);
        $arq = Armazenamento::gravar($accountId, $bytes, 'pdf');
        $agora = Tempo::agora();

        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM crm_contratos WHERE id = ? AND account_id = ? FOR UPDATE')->execute([$id, $accountId]);
            $pdo->prepare(
                'INSERT INTO crm_contrato_arquivos (uuid, account_id, contrato_id, tipo, sha256, bytes, mime, caminho, criado_em, criado_por)
                 VALUES (?, ?, ?, \'original\', ?, ?, \'application/pdf\', ?, ?, ?)'
            )->execute([Cripto::uuid(), $accountId, $id, $arq['sha256'], $arq['bytes'], $arq['caminho'], $agora, $userId]);
            $arquivoId = (int) $pdo->lastInsertId();

            $r = $pdo->prepare("SELECT id FROM crm_contrato_versoes WHERE contrato_id = ? AND account_id = ? AND status = 'rascunho' ORDER BY numero DESC LIMIT 1");
            $r->execute([$id, $accountId]);
            $rascunho = (int) ($r->fetchColumn() ?: 0);
            if ($rascunho) {
                $pdo->prepare('UPDATE crm_contrato_versoes SET arquivo_id = ?, sha256 = ?, nome_arquivo = ? WHERE id = ? AND aberta_em IS NULL')
                    ->execute([$arquivoId, $arq['sha256'], $nome, $rascunho]);
                $versaoId = $rascunho;
            } else {
                $n = $pdo->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM crm_contrato_versoes WHERE contrato_id = ?');
                $n->execute([$id]);
                $pdo->prepare(
                    'INSERT INTO crm_contrato_versoes (uuid, account_id, contrato_id, numero, arquivo_id, sha256, nome_arquivo, status, criado_por, criado_em)
                     VALUES (?, ?, ?, ?, ?, ?, ?, \'rascunho\', ?, ?)'
                )->execute([Cripto::uuid(), $accountId, $id, (int) $n->fetchColumn(), $arquivoId, $arq['sha256'], $nome, $userId, $agora]);
                $versaoId = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('UPDATE crm_contratos SET atualizado_em = ? WHERE id = ?')->execute([$agora, $id]);
            Eventos::registrar($id, 'documento_anexado', ['sha256' => $arq['sha256'], 'bytes' => $arq['bytes'], 'nome' => $nome], ['user_id' => $userId, 'versao_id' => $versaoId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['versao_id' => $versaoId, 'sha256' => $arq['sha256']];
    }

    // ── Participantes ─────────────────────────────────────────────────────

    public static function salvarParticipante(int $accountId, int $userId, int $contratoId, array $d): int
    {
        $c = self::doContrato($accountId, $contratoId);
        if (in_array($c['status'], ['concluido', 'cancelado', 'recusado', 'expirado'], true)) {
            throw new \InvalidArgumentException('Contrato encerrado não muda os participantes.');
        }
        $pdo = Database::getConnection();
        $papel = (string) ($d['papel'] ?? '');
        if (!isset(self::PAPEIS[$papel])) throw new \InvalidArgumentException('Escolha o papel do participante.');
        $nome = trim((string) ($d['nome'] ?? ''));
        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 160) throw new \InvalidArgumentException('Informe o nome completo do participante.');
        $id = !empty($d['id']) ? (int) $d['id'] : 0;
        $atual = $id ? self::doParticipante($accountId, $contratoId, $id) : null;
        // Na edição, WhatsApp e CPF em branco mantêm o que está (a tela não mostra os números inteiros).
        $telBruto = trim((string) ($d['telefone'] ?? ''));
        $tel = $telBruto === '' && $atual ? Cripto::decifrar($atual['telefone_cifrado']) : Entrega::numeroInternacional($telBruto);
        if ($tel === null || $tel === '') throw new \InvalidArgumentException('Informe o WhatsApp do participante com DDD.');
        $cpfBruto = trim((string) ($d['cpf'] ?? ''));
        $cpf = $cpfBruto === '' && $atual ? (string) Cripto::decifrar($atual['cpf_cifrado']) : Cpf::digitos($cpfBruto);
        if ($cpf !== '' && !Cpf::valido($cpf)) throw new \InvalidArgumentException('O CPF esperado não é válido.');
        $empresa = trim((string) ($d['empresa'] ?? ''));
        $cnpj = preg_replace('/\D+/', '', (string) ($d['cnpj'] ?? '')) ?? '';
        $funcao = trim((string) ($d['funcao'] ?? ''));
        if ($papel === 'representante' && ($empresa === '' || strlen($cnpj) !== 14 || $funcao === '')) {
            throw new \InvalidArgumentException('Representante precisa da empresa representada, do CNPJ e da função.');
        }
        if ($cnpj !== '' && strlen($cnpj) !== 14) throw new \InvalidArgumentException('CNPJ inválido.');
        $clienteId = !empty($d['cliente_id']) ? (int) $d['cliente_id'] : null;
        if ($clienteId) {
            $st = $pdo->prepare('SELECT id FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
            $st->execute([$clienteId, $accountId]);
            if (!$st->fetchColumn()) throw new \InvalidArgumentException('Cliente não encontrado nesta conta.');
        }
        $obrig = !isset($d['obrigatorio']) || (bool) $d['obrigatorio'];
        $agora = Tempo::agora();

        $valores = [
            'papel' => $papel, 'obrigatorio' => $obrig ? 1 : 0, 'nome' => $nome,
            'telefone_cifrado' => Cripto::cifrar($tel), 'telefone_final' => substr($tel, -4),
            'cpf_cifrado' => $cpf !== '' ? Cripto::cifrar($cpf) : null, 'cpf_hmac' => $cpf !== '' ? Cpf::hmac($cpf) : null,
            'cpf_mascara' => $cpf !== '' ? Cpf::mascarar($cpf) : null,
            'empresa' => $empresa ?: null, 'cnpj' => $cnpj ?: null, 'funcao' => $funcao ?: null, 'cliente_id' => $clienteId,
        ];

        if ($id) {
            $p = $atual;
            if ($p['status'] === 'assinou') throw new \InvalidArgumentException('Quem já assinou esta versão não muda. Para corrigir, envie uma nova versão.');
            $mudouContato = $p['telefone_final'] !== $valores['telefone_final'] || Cripto::decifrar($p['telefone_cifrado']) !== $tel
                || ($p['cpf_hmac'] ?? null) !== $valores['cpf_hmac'];
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($valores)));
            $pdo->prepare("UPDATE crm_contrato_participantes SET $sets, atualizado_em = ? WHERE id = ? AND account_id = ?")
                ->execute([...array_values($valores), $agora, $id, $accountId]);
            if ($mudouContato && in_array($p['status'], ['convidado', 'bloqueado'], true)) {
                // Contato ou CPF esperado mudou: o convite antigo morre e precisa de um novo.
                Convite::revogarDoParticipante($id, 'contato_alterado');
                $pdo->prepare("UPDATE crm_contrato_participantes SET status = 'pendente', bloqueio_motivo = NULL WHERE id = ?")->execute([$id]);
            }
            Eventos::registrar($contratoId, 'participante_editado', ['papel' => $papel, 'nome' => $nome, 'contato_alterado' => $mudouContato],
                ['user_id' => $userId, 'participante_id' => $id]);
            return $id;
        }

        $o = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM crm_contrato_participantes WHERE contrato_id = ?');
        $o->execute([$contratoId]);
        $cols = array_keys($valores);
        $pdo->prepare('INSERT INTO crm_contrato_participantes (uuid, account_id, contrato_id, ordem, ' . implode(', ', $cols) . ', criado_em, atualizado_em)
                       VALUES (?, ?, ?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?)')
            ->execute([Cripto::uuid(), $accountId, $contratoId, (int) $o->fetchColumn(), ...array_values($valores), $agora, $agora]);
        $novo = (int) $pdo->lastInsertId();
        Eventos::registrar($contratoId, 'participante_incluido', ['papel' => $papel, 'nome' => $nome], ['user_id' => $userId, 'participante_id' => $novo]);
        return $novo;
    }

    public static function removerParticipante(int $accountId, int $userId, int $contratoId, int $participanteId): void
    {
        $c = self::doContrato($accountId, $contratoId);
        $p = self::doParticipante($accountId, $contratoId, $participanteId);
        $pdo = Database::getConnection();
        $as = $pdo->prepare('SELECT COUNT(*) FROM crm_contrato_assinaturas WHERE participante_id = ?');
        $as->execute([$participanteId]);
        if ((int) $as->fetchColumn() > 0) throw new \InvalidArgumentException('Participante com assinatura registrada não sai do contrato.');
        Convite::revogarDoParticipante($participanteId, 'participante_removido');
        $pdo->prepare("UPDATE crm_contrato_participantes SET status = 'cancelado', atualizado_em = ? WHERE id = ? AND account_id = ?")
            ->execute([Tempo::agora(), $participanteId, $accountId]);
        Eventos::registrar($contratoId, 'participante_removido', ['nome' => $p['nome']], ['user_id' => $userId, 'participante_id' => $participanteId]);
        if (in_array($c['status'], self::ABERTOS, true)) self::recalcular($contratoId);
    }

    // ── Fechar a versão e convidar ────────────────────────────────────────

    /**
     * Fecha a versão em rascunho (o SHA-256 dos bytes já foi calculado ao anexar e
     * agora não muda mais), substitui a versão aberta anterior se houver (convites
     * revogados, assinaturas antigas preservadas e marcadas na versão substituída) e
     * emite um convite por participante.
     *
     * @return array{versao_id:int, links:array<int,array{participante_id:int, nome:string, link:string, enviado:?bool, erro:?string}>}
     */
    public static function fecharEEnviar(int $accountId, int $userId, int $id, bool $enviarWhatsapp): array
    {
        $pdo = Database::getConnection();
        $cfg = self::config($accountId);
        $links = [];
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM crm_contratos WHERE id = ? AND account_id = ? FOR UPDATE');
            $st->execute([$id, $accountId]);
            $c = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$c) throw new \InvalidArgumentException('Contrato não encontrado.');
            if (!in_array($c['status'], ['rascunho', 'aguardando', 'parcial'], true)) throw new \InvalidArgumentException('Contrato encerrado não recebe nova versão.');
            if ($c['prazo_em'] && Tempo::passou((string) $c['prazo_em'])) throw new \InvalidArgumentException('O prazo para assinatura já passou. Ajuste o prazo antes de enviar.');

            $r = $pdo->prepare("SELECT * FROM crm_contrato_versoes WHERE contrato_id = ? AND status = 'rascunho' ORDER BY numero DESC LIMIT 1");
            $r->execute([$id]);
            $v = $r->fetch(\PDO::FETCH_ASSOC);
            if (!$v) throw new \InvalidArgumentException('Anexe o PDF do contrato antes de enviar.');
            $arq = $pdo->prepare('SELECT * FROM crm_contrato_arquivos WHERE id = ? AND account_id = ?');
            $arq->execute([(int) $v['arquivo_id'], $accountId]);
            $a = $arq->fetch(\PDO::FETCH_ASSOC);
            if (!$a || !hash_equals((string) $a['sha256'], (string) $v['sha256']) || !Armazenamento::existeIntegro((string) $a['caminho'], (string) $a['sha256'])) {
                throw new \RuntimeException('O PDF desta versão não confere com o hash gravado.');
            }

            $ps = $pdo->prepare("SELECT * FROM crm_contrato_participantes WHERE contrato_id = ? AND status <> 'cancelado' ORDER BY ordem, id");
            $ps->execute([$id]);
            $parts = $ps->fetchAll(\PDO::FETCH_ASSOC);
            if (!array_filter($parts, fn($p) => (int) $p['obrigatorio'] === 1)) throw new \InvalidArgumentException('Inclua pelo menos um participante obrigatório.');

            $agora = Tempo::agora();
            $anterior = $c['versao_atual_id'] ? (int) $c['versao_atual_id'] : null;
            if ($anterior) {
                $pdo->prepare("UPDATE crm_contrato_versoes SET status = 'substituida', substituida_em = ?, substituida_por_id = ? WHERE id = ? AND status = 'aberta'")
                    ->execute([$agora, (int) $v['id'], $anterior]);
                Convite::revogarDoContrato($id, 'nova_versao');
                Eventos::registrar($id, 'versao_substituida', ['versao_anterior_id' => $anterior, 'nova_versao_id' => (int) $v['id']], ['user_id' => $userId, 'versao_id' => $anterior]);
            }
            $pdo->prepare("UPDATE crm_contrato_versoes SET status = 'aberta', aberta_em = ? WHERE id = ? AND aberta_em IS NULL")->execute([$agora, (int) $v['id']]);
            $pdo->prepare("UPDATE crm_contratos SET status = 'aguardando', versao_atual_id = ?, atualizado_em = ? WHERE id = ?")->execute([(int) $v['id'], $agora, $id]);
            Eventos::registrar($id, 'versao_fechada', ['numero' => (int) $v['numero'], 'sha256' => (string) $v['sha256'], 'versao_uuid' => (string) $v['uuid']],
                ['user_id' => $userId, 'versao_id' => (int) $v['id']]);

            foreach ($parts as $p) {
                // Bloqueio por divergência espera revisão; o resto recebe convite para a versão nova.
                if ($p['status'] === 'bloqueado') continue;
                $pdo->prepare("UPDATE crm_contrato_participantes SET status = 'convidado', atualizado_em = ? WHERE id = ?")->execute([$agora, (int) $p['id']]);
                $cv = Convite::emitir($accountId, $id, (int) $p['id'], (int) $v['id'], $userId, $cfg['convite_validade_dias'], $c['prazo_em']);
                Eventos::registrar($id, 'convite_emitido', ['convite_uuid' => $cv['uuid'], 'expira_em' => $cv['expira_em']],
                    ['user_id' => $userId, 'versao_id' => (int) $v['id'], 'participante_id' => (int) $p['id']]);
                $links[] = ['participante_id' => (int) $p['id'], 'nome' => (string) $p['nome'], 'token' => $cv['token'], 'convite_id' => $cv['id'], 'enviado' => null, 'erro' => null];
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        foreach ($links as &$l) {
            $l['link'] = Convite::link($accountId, $l['token']);
            if ($enviarWhatsapp) {
                $env = self::enviarConvite($accountId, $id, $l['participante_id'], $l['convite_id'], $l['link'], $userId);
                $l['enviado'] = $env['ok'];
                $l['erro'] = $env['erro'] ?? null;
            }
            unset($l['token'], $l['convite_id']);
        }
        unset($l);
        return ['versao_id' => (int) $v['id'], 'links' => $links];
    }

    /**
     * Novo link para um participante (copiar de novo, reenviar, liberar bloqueio):
     * revoga o anterior. Bloqueado só volta com $liberar (revisão da equipe).
     * @return array{link:string, enviado:?bool, erro:?string}
     */
    public static function novoLink(int $accountId, int $userId, int $contratoId, int $participanteId, bool $enviarWhatsapp, bool $liberar = false, string $motivo = ''): array
    {
        $pdo = Database::getConnection();
        $cfg = self::config($accountId);
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM crm_contratos WHERE id = ? AND account_id = ? FOR UPDATE');
            $st->execute([$contratoId, $accountId]);
            $c = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$c) throw new \InvalidArgumentException('Contrato não encontrado.');
            if (!in_array($c['status'], self::ABERTOS, true) || !$c['versao_atual_id']) throw new \InvalidArgumentException('Este contrato não está aguardando assinaturas.');
            if ($c['prazo_em'] && Tempo::passou((string) $c['prazo_em'])) throw new \InvalidArgumentException('O prazo para assinatura já passou.');
            $p = self::doParticipante($accountId, $contratoId, $participanteId);
            if ($p['status'] === 'assinou') throw new \InvalidArgumentException('Este participante já assinou a versão atual.');
            if (in_array($p['status'], ['recusou', 'cancelado'], true)) throw new \InvalidArgumentException('Este participante não pode receber convite.');
            if ($p['status'] === 'bloqueado' && !$liberar) throw new \InvalidArgumentException('Participante bloqueado: revise e libere antes de mandar novo convite.');
            if ($liberar) {
                if (trim($motivo) === '') throw new \InvalidArgumentException('Explique a revisão feita antes de liberar.');
                Eventos::registrar($contratoId, 'bloqueio_liberado', ['motivo' => mb_substr(trim($motivo), 0, 300), 'bloqueio_anterior' => $p['bloqueio_motivo']],
                    ['user_id' => $userId, 'participante_id' => $participanteId, 'versao_id' => (int) $c['versao_atual_id']]);
            }
            $pdo->prepare("UPDATE crm_contrato_participantes SET status = 'convidado', bloqueio_motivo = NULL, atualizado_em = ? WHERE id = ?")
                ->execute([Tempo::agora(), $participanteId]);
            $cv = Convite::emitir($accountId, $contratoId, $participanteId, (int) $c['versao_atual_id'], $userId, $cfg['convite_validade_dias'], $c['prazo_em']);
            Eventos::registrar($contratoId, 'convite_reemitido', ['convite_uuid' => $cv['uuid'], 'expira_em' => $cv['expira_em']],
                ['user_id' => $userId, 'participante_id' => $participanteId, 'versao_id' => (int) $c['versao_atual_id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $link = Convite::link($accountId, $cv['token']);
        $res = ['link' => $link, 'enviado' => null, 'erro' => null];
        if ($enviarWhatsapp) {
            $env = self::enviarConvite($accountId, $contratoId, $participanteId, $cv['id'], $link, $userId);
            $res['enviado'] = $env['ok'];
            $res['erro'] = $env['erro'] ?? null;
        }
        return $res;
    }

    private static function enviarConvite(int $accountId, int $contratoId, int $participanteId, int $conviteId, string $link, int $userId): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT p.nome, p.telefone_cifrado, p.telefone_final, c.titulo FROM crm_contrato_participantes p JOIN crm_contratos c ON c.id = p.contrato_id
                              WHERE p.id = ? AND p.account_id = ?');
        $st->execute([$participanteId, $accountId]);
        $p = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$p) return ['ok' => false, 'erro' => 'Participante não encontrado.'];
        $marca = self::nomeDaMarca($accountId);
        $primeiro = explode(' ', trim((string) $p['nome']))[0];
        $texto = "Olá, {$primeiro}. {$marca} enviou o contrato \"{$p['titulo']}\" para sua assinatura eletrônica.\n\n"
            . "Abra o link para conferir o documento e assinar:\n{$link}\n\n"
            . "Para confirmar que é você, vamos mandar um código para este WhatsApp.";
        $r = Entrega::whatsapp($accountId, (string) Cripto::decifrar($p['telefone_cifrado']), $texto);
        if ($r['ok']) $pdo->prepare('UPDATE crm_contrato_convites SET enviado_em = ? WHERE id = ?')->execute([Tempo::agora(), $conviteId]);
        $vc = $pdo->prepare('SELECT versao_id FROM crm_contrato_convites WHERE id = ?');
        $vc->execute([$conviteId]);
        $versaoDoConvite = (int) $vc->fetchColumn();
        Eventos::registrar($contratoId, $r['ok'] ? 'convite_enviado' : 'convite_nao_enviado',
            ['canal' => 'whatsapp', 'contato' => 'WhatsApp terminado em ' . $p['telefone_final']] + ($r['ok'] ? [] : ['erro' => $r['erro'] ?? '']),
            ['user_id' => $userId, 'participante_id' => $participanteId, 'versao_id' => $versaoDoConvite ?: null]);
        return $r;
    }

    // ── Encerrar ──────────────────────────────────────────────────────────

    /** Cancela o fluxo. Não apaga assinatura nem desfaz contrato já celebrado: só barra novas assinaturas. */
    public static function cancelar(int $accountId, int $userId, int $id, string $motivo): void
    {
        $motivo = trim($motivo);
        if ($motivo === '') throw new \InvalidArgumentException('Informe o motivo do cancelamento.');
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM crm_contratos WHERE id = ? AND account_id = ? FOR UPDATE');
            $st->execute([$id, $accountId]);
            $c = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$c) throw new \InvalidArgumentException('Contrato não encontrado.');
            if (!in_array($c['status'], ['rascunho', 'aguardando', 'parcial'], true)) throw new \InvalidArgumentException('Só contrato em andamento pode ser cancelado.');
            $agora = Tempo::agora();
            $pdo->prepare("UPDATE crm_contratos SET status = 'cancelado', encerrado_em = ?, encerrado_por = ?, motivo_encerramento = ?, atualizado_em = ? WHERE id = ?")
                ->execute([$agora, $userId, mb_substr($motivo, 0, 500), $agora, $id]);
            if ($c['versao_atual_id']) $pdo->prepare("UPDATE crm_contrato_versoes SET status = 'encerrada' WHERE id = ? AND status = 'aberta'")->execute([(int) $c['versao_atual_id']]);
            Convite::revogarDoContrato($id, 'cancelado');
            $pdo->prepare("UPDATE crm_contrato_sessoes SET encerrada_em = ? WHERE contrato_id = ? AND encerrada_em IS NULL")->execute([$agora, $id]);
            Eventos::registrar($id, 'cancelado', ['motivo' => mb_substr($motivo, 0, 500), 'situacao_anterior' => $c['status']], ['user_id' => $userId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Contratos com prazo vencido viram expirados (worker). @return int quantos */
    public static function expirarVencidos(): int
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT id FROM crm_contratos WHERE status IN ('rascunho','aguardando','parcial') AND prazo_em IS NOT NULL AND prazo_em < ? LIMIT 200");
        $st->execute([Tempo::agora()]);
        $n = 0;
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $pdo->beginTransaction();
            try {
                $l = $pdo->prepare("SELECT status, versao_atual_id FROM crm_contratos WHERE id = ? AND status IN ('rascunho','aguardando','parcial') AND prazo_em < ? FOR UPDATE");
                $l->execute([(int) $id, Tempo::agora()]);
                $c = $l->fetch(\PDO::FETCH_ASSOC);
                if (!$c) { $pdo->rollBack(); continue; }
                $agora = Tempo::agora();
                $pdo->prepare("UPDATE crm_contratos SET status = 'expirado', encerrado_em = ?, motivo_encerramento = 'Prazo para assinatura vencido', atualizado_em = ? WHERE id = ?")
                    ->execute([$agora, $agora, (int) $id]);
                if ($c['versao_atual_id']) $pdo->prepare("UPDATE crm_contrato_versoes SET status = 'encerrada' WHERE id = ? AND status = 'aberta'")->execute([(int) $c['versao_atual_id']]);
                $pdo->prepare("UPDATE crm_contrato_participantes SET status = 'expirado' WHERE contrato_id = ? AND status IN ('pendente','convidado','bloqueado')")->execute([(int) $id]);
                Convite::revogarDoContrato((int) $id, 'expirado');
                Eventos::registrar((int) $id, 'expirado', ['situacao_anterior' => $c['status']]);
                $pdo->commit();
                $n++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[contratos] falha ao expirar ' . $id . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /** Recalcula a situação depois de assinatura ou remoção. Chamar dentro ou fora de transação. */
    public static function recalcular(int $contratoId): string
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT status, versao_atual_id FROM crm_contratos WHERE id = ?');
        $st->execute([$contratoId]);
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$c || !in_array($c['status'], self::ABERTOS, true)) return (string) ($c['status'] ?? '');
        $q = $pdo->prepare(
            "SELECT SUM(p.obrigatorio = 1) AS obrig,
                    SUM(p.obrigatorio = 1 AND a.id IS NOT NULL) AS obrig_ok,
                    SUM(a.id IS NOT NULL) AS assinadas
               FROM crm_contrato_participantes p
               LEFT JOIN crm_contrato_assinaturas a ON a.participante_id = p.id AND a.versao_id = ?
              WHERE p.contrato_id = ? AND p.status <> 'cancelado'"
        );
        $q->execute([(int) $c['versao_atual_id'], $contratoId]);
        $r = $q->fetch(\PDO::FETCH_ASSOC);
        $agora = Tempo::agora();
        if ((int) $r['obrig'] > 0 && (int) $r['obrig_ok'] === (int) $r['obrig']) {
            $pdo->prepare("UPDATE crm_contratos SET status = 'concluido', concluido_em = ?, atualizado_em = ?, finalizacao_status = 'pendente', finalizacao_proxima = ? WHERE id = ?")
                ->execute([$agora, $agora, $agora, $contratoId]);
            $pdo->prepare("UPDATE crm_contrato_versoes SET status = 'concluida' WHERE id = ?")->execute([(int) $c['versao_atual_id']]);
            Convite::revogarDoContrato($contratoId, 'concluido');
            Eventos::registrar($contratoId, 'concluido', ['assinaturas' => (int) $r['assinadas']], ['versao_id' => (int) $c['versao_atual_id']]);
            return 'concluido';
        }
        $novo = (int) $r['assinadas'] > 0 ? 'parcial' : 'aguardando';
        if ($novo !== $c['status']) $pdo->prepare('UPDATE crm_contratos SET status = ?, atualizado_em = ? WHERE id = ?')->execute([$novo, $agora, $contratoId]);
        return $novo;
    }

    // ── Leitura ───────────────────────────────────────────────────────────

    public static function doContrato(int $accountId, int $id): array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM crm_contratos WHERE id = ? AND account_id = ?');
        $st->execute([$id, $accountId]);
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$c) throw new \InvalidArgumentException('Contrato não encontrado.');
        return $c;
    }

    public static function doParticipante(int $accountId, int $contratoId, int $participanteId): array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM crm_contrato_participantes WHERE id = ? AND contrato_id = ? AND account_id = ?');
        $st->execute([$participanteId, $contratoId, $accountId]);
        $p = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$p) throw new \InvalidArgumentException('Participante não encontrado.');
        return $p;
    }

    /** @param array{status?:string, cliente_id?:int, card_id?:int, busca?:string} $f */
    public static function listar(int $accountId, array $f = []): array
    {
        $where = ['c.account_id = ?'];
        $args = [$accountId];
        if (!empty($f['status']) && isset(self::STATUS[$f['status']])) { $where[] = 'c.status = ?'; $args[] = $f['status']; }
        if (!empty($f['cliente_id'])) { $where[] = 'c.cliente_id = ?'; $args[] = (int) $f['cliente_id']; }
        if (!empty($f['sem_cliente'])) $where[] = 'c.cliente_id IS NULL';
        if (!empty($f['card_id'])) { $where[] = 'c.card_id = ?'; $args[] = (int) $f['card_id']; }
        if (!empty($f['busca'])) { $where[] = 'c.titulo LIKE ?'; $args[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['busca']) . '%'; }
        $st = Database::getConnection()->prepare(
            "SELECT c.id, c.uuid, c.titulo, c.status, c.prazo_em, c.criado_em, c.concluido_em, c.atualizado_em, c.codigo_verificacao,
                    u.nome AS responsavel, cl.nome AS cliente, COALESCE(NULLIF(cd.cliente_nome,''), cd.empresa_nome, cd.titulo) AS negociacao,
                    c.cliente_id, c.card_id,
                    (SELECT COUNT(*) FROM crm_contrato_participantes p WHERE p.contrato_id = c.id AND p.status <> 'cancelado') AS participantes,
                    (SELECT COUNT(*) FROM crm_contrato_assinaturas a WHERE a.contrato_id = c.id AND a.versao_id = c.versao_atual_id) AS assinaturas,
                    (SELECT v.aberta_em FROM crm_contrato_versoes v WHERE v.id = c.versao_atual_id) AS enviado_em,
                    (SELECT v.numero FROM crm_contrato_versoes v WHERE v.contrato_id = c.id ORDER BY v.numero DESC LIMIT 1) AS versao,
                    (SELECT COUNT(DISTINCT e.participante_id) FROM crm_contrato_eventos e
                      WHERE e.contrato_id = c.id AND e.tipo = 'acesso' AND e.versao_id = c.versao_atual_id) AS abriram
               FROM crm_contratos c
               LEFT JOIN users u ON u.id = c.responsavel_id
               LEFT JOIN clientes cl ON cl.id = c.cliente_id AND cl.account_id = c.account_id
               LEFT JOIN cards cd ON cd.id = c.card_id AND cd.account_id = c.account_id
              WHERE " . implode(' AND ', $where) . ' ORDER BY c.atualizado_em DESC LIMIT 300'
        );
        $st->execute($args);
        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        // Quem participa de cada contrato (nome, papel e situação), para o card.
        $pessoas = [];
        if ($linhas) {
            $ids = implode(',', array_map(fn($r) => (int) $r['id'], $linhas));
            foreach (Database::getConnection()->query("SELECT contrato_id, nome, papel, status FROM crm_contrato_participantes
                                                         WHERE contrato_id IN ($ids) AND status <> 'cancelado' ORDER BY ordem, id") as $p) {
                $pessoas[(int) $p['contrato_id']][] = ['nome' => $p['nome'], 'papel' => self::PAPEIS[$p['papel']] ?? $p['papel'],
                    'status' => $p['status'], 'status_rotulo' => self::STATUS_PARTICIPANTE[$p['status']] ?? $p['status']];
            }
        }
        return array_map(static fn($r) => [
            'id' => (int) $r['id'], 'uuid' => $r['uuid'], 'titulo' => $r['titulo'], 'status' => $r['status'],
            'status_rotulo' => self::STATUS[$r['status']] ?? $r['status'],
            'prazo' => Tempo::local($r['prazo_em']), 'criado_em' => Tempo::local($r['criado_em']),
            'concluido_em' => Tempo::local($r['concluido_em']), 'atualizado_em' => Tempo::local($r['atualizado_em']),
            'responsavel' => $r['responsavel'], 'cliente' => $r['cliente'], 'negociacao' => $r['negociacao'],
            'cliente_id' => $r['cliente_id'] ? (int) $r['cliente_id'] : null, 'card_id' => $r['card_id'] ? (int) $r['card_id'] : null,
            'participantes' => (int) $r['participantes'], 'assinaturas' => (int) $r['assinaturas'],
            'codigo_verificacao' => $r['codigo_verificacao'],
            'enviado_em' => Tempo::local($r['enviado_em']), 'abriram' => (int) $r['abriram'], 'versao' => $r['versao'] ? (int) $r['versao'] : null,
            'pessoas' => $pessoas[(int) $r['id']] ?? [],
        ], $linhas);
    }

    /** A central da conta: quantos contratos, em que situação, e quanto disco ocupam. */
    public static function central(int $accountId): array
    {
        $pdo = Database::getConnection();
        $s = $pdo->prepare('SELECT status, COUNT(*) n FROM crm_contratos WHERE account_id = ? GROUP BY status');
        $s->execute([$accountId]);
        $porStatus = array_fill_keys(array_keys(self::STATUS), 0);
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $r) $porStatus[$r['status']] = (int) $r['n'];
        $b = $pdo->prepare(
            "SELECT COALESCE(SUM(a.bytes), 0) AS total,
                    COALESCE(SUM(CASE WHEN c.status = 'concluido' THEN a.bytes ELSE 0 END), 0) AS assinados,
                    COUNT(DISTINCT CASE WHEN c.status = 'concluido' THEN c.id END) AS contratos_assinados
               FROM crm_contrato_arquivos a JOIN crm_contratos c ON c.id = a.contrato_id
              WHERE a.account_id = ?"
        );
        $b->execute([$accountId]);
        $r = $b->fetch(\PDO::FETCH_ASSOC);
        $as = $pdo->prepare('SELECT COUNT(*) FROM crm_contrato_assinaturas WHERE account_id = ?');
        $as->execute([$accountId]);
        return [
            'total' => array_sum($porStatus), 'por_status' => $porStatus,
            'assinaturas' => (int) $as->fetchColumn(),
            'bytes_total' => (int) $r['total'], 'bytes_assinados' => (int) $r['assinados'],
            'contratos_assinados' => (int) $r['contratos_assinados'],
        ];
    }

    /** A ficha completa, para a equipe. CPF e telefone só mascarados. */
    public static function ficha(int $accountId, int $id): array
    {
        $c = self::doContrato($accountId, $id);
        $pdo = Database::getConnection();

        $v = $pdo->prepare('SELECT v.*, a.bytes, a.uuid AS arquivo_uuid FROM crm_contrato_versoes v JOIN crm_contrato_arquivos a ON a.id = v.arquivo_id
                             WHERE v.contrato_id = ? AND v.account_id = ? ORDER BY v.numero DESC');
        $v->execute([$id, $accountId]);
        $versoes = array_map(static fn($x) => [
            'id' => (int) $x['id'], 'uuid' => $x['uuid'], 'numero' => (int) $x['numero'], 'status' => $x['status'],
            'sha256' => $x['sha256'], 'nome_arquivo' => $x['nome_arquivo'], 'bytes' => (int) $x['bytes'], 'arquivo_uuid' => $x['arquivo_uuid'],
            'aberta_em' => Tempo::local($x['aberta_em']), 'substituida_em' => Tempo::local($x['substituida_em']), 'criado_em' => Tempo::local($x['criado_em']),
        ], $v->fetchAll(\PDO::FETCH_ASSOC));

        $p = $pdo->prepare('SELECT * FROM crm_contrato_participantes WHERE contrato_id = ? AND account_id = ? ORDER BY ordem, id');
        $p->execute([$id, $accountId]);
        $assin = $pdo->prepare('SELECT a.*, v.numero FROM crm_contrato_assinaturas a JOIN crm_contrato_versoes v ON v.id = a.versao_id WHERE a.contrato_id = ? AND a.account_id = ? ORDER BY a.id');
        $assin->execute([$id, $accountId]);
        $porParticipante = [];
        foreach ($assin->fetchAll(\PDO::FETCH_ASSOC) as $a) {
            $ident = json_decode((string) $a['identificacao_json'], true) ?: [];
            $porParticipante[(int) $a['participante_id']][] = [
                'uuid' => $a['uuid'], 'versao' => (int) $a['numero'], 'versao_atual' => (int) $a['versao_id'] === (int) $c['versao_atual_id'],
                'assinado_em' => Tempo::local($a['assinado_em'], true), 'metodo' => $a['metodo'], 'contato' => $a['contato_mascarado'],
                'ip' => $a['ip'], 'nome_declarado' => $ident['nome'] ?? null, 'cpf' => $ident['cpf_mascara'] ?? null,
                'origem' => $ident['origem'] ?? null, 'evidencia_sha256' => $a['evidencia_sha256'],
            ];
        }
        $cv = $pdo->prepare('SELECT participante_id, MAX(criado_em) AS ultimo, MAX(enviado_em) AS enviado, MAX(expira_em) AS expira,
                                    SUM(revogado_em IS NULL AND usado_em IS NULL) AS vivos
                               FROM crm_contrato_convites WHERE contrato_id = ? AND account_id = ? GROUP BY participante_id');
        $cv->execute([$id, $accountId]);
        $convites = [];
        foreach ($cv->fetchAll(\PDO::FETCH_ASSOC) as $x) $convites[(int) $x['participante_id']] = $x;

        // Acompanhamento: o que cada participante já fez na versão em assinatura (a partir de quando ela
        // foi enviada). Abrir o link e ver o PDF não são assinatura nem prova de leitura.
        $abertaEm = null;
        if ($c['versao_atual_id']) {
            $va = $pdo->prepare('SELECT aberta_em FROM crm_contrato_versoes WHERE id = ?');
            $va->execute([(int) $c['versao_atual_id']]);
            $abertaEm = $va->fetchColumn() ?: null;
        }
        $passos = [];
        if ($abertaEm) {
            $ev = $pdo->prepare("SELECT participante_id, tipo, MIN(criado_em) AS primeiro, MAX(criado_em) AS ultimo, COUNT(*) AS vezes
                                   FROM crm_contrato_eventos WHERE contrato_id = ? AND participante_id IS NOT NULL AND criado_em >= ?
                                  GROUP BY participante_id, tipo");
            $ev->execute([$id, $abertaEm]);
            foreach ($ev->fetchAll(\PDO::FETCH_ASSOC) as $e) {
                $passos[(int) $e['participante_id']][$e['tipo']] = ['primeiro' => Tempo::local($e['primeiro']), 'ultimo' => Tempo::local($e['ultimo']), 'vezes' => (int) $e['vezes']];
            }
        }
        $participantes = array_map(static function ($x) use ($porParticipante, $convites, $passos) {
            $pid = (int) $x['id'];
            $cvx = $convites[$pid] ?? null;
            return [
                'id' => $pid, 'uuid' => $x['uuid'], 'papel' => $x['papel'], 'papel_rotulo' => self::PAPEIS[$x['papel']] ?? $x['papel'],
                'obrigatorio' => (bool) $x['obrigatorio'], 'nome' => $x['nome'], 'whatsapp' => 'terminado em ' . $x['telefone_final'],
                'cpf' => $x['cpf_mascara'], 'empresa' => $x['empresa'], 'cnpj' => $x['cnpj'], 'funcao' => $x['funcao'],
                'cliente_id' => $x['cliente_id'] ? (int) $x['cliente_id'] : null,
                'status' => $x['status'], 'status_rotulo' => self::STATUS_PARTICIPANTE[$x['status']] ?? $x['status'],
                'bloqueio_motivo' => $x['bloqueio_motivo'],
                'convite' => $cvx ? ['ultimo' => Tempo::local($cvx['ultimo']), 'enviado' => Tempo::local($cvx['enviado']),
                                      'expira' => Tempo::local($cvx['expira']), 'ativo' => (int) $cvx['vivos'] > 0] : null,
                'assinaturas' => $porParticipante[$pid] ?? [],
                'acompanhamento' => self::passosDoParticipante($passos[$pid] ?? []),
            ];
        }, $p->fetchAll(\PDO::FETCH_ASSOC));
        $eventosTodos = Eventos::doContrato($id);
        $primeiroEvento = function (string $tipo) use ($eventosTodos) {
            foreach ($eventosTodos as $e) if ($e['tipo'] === $tipo) return Tempo::local(substr(str_replace('T', ' ', $e['criado_em']), 0, 19));
            return null;
        };
        $obrig = array_filter($participantes, fn($p) => $p['obrigatorio'] && $p['status'] !== 'cancelado');
        $assinaram = array_filter($obrig, fn($p) => $p['status'] === 'assinou');

        $ar = $pdo->prepare("SELECT uuid, tipo, sha256, bytes, criado_em FROM crm_contrato_arquivos WHERE contrato_id = ? AND account_id = ? AND tipo <> 'original' ORDER BY id");
        $ar->execute([$id, $accountId]);
        $finais = array_map(static fn($x) => ['uuid' => $x['uuid'], 'tipo' => $x['tipo'], 'sha256' => $x['sha256'], 'bytes' => (int) $x['bytes'],
            'criado_em' => Tempo::local($x['criado_em'])], $ar->fetchAll(\PDO::FETCH_ASSOC));

        $rel = $pdo->prepare('SELECT (SELECT nome FROM users WHERE id = ?) AS resp, (SELECT nome FROM clientes WHERE id = ? AND account_id = ?) AS cli,
                                     (SELECT COALESCE(NULLIF(cliente_nome,\'\'), empresa_nome, titulo) FROM cards WHERE id = ? AND account_id = ?) AS neg');
        $rel->execute([(int) $c['responsavel_id'], (int) $c['cliente_id'], $accountId, (int) $c['card_id'], $accountId]);
        $nomes = $rel->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'id' => (int) $c['id'], 'uuid' => $c['uuid'], 'titulo' => $c['titulo'], 'status' => $c['status'],
            'status_rotulo' => self::STATUS[$c['status']] ?? $c['status'], 'codigo_verificacao' => $c['codigo_verificacao'],
            'responsavel_id' => $c['responsavel_id'] ? (int) $c['responsavel_id'] : null, 'responsavel' => $nomes['resp'] ?? null,
            'cliente_id' => $c['cliente_id'] ? (int) $c['cliente_id'] : null, 'cliente' => $nomes['cli'] ?? null,
            'card_id' => $c['card_id'] ? (int) $c['card_id'] : null, 'negociacao' => $nomes['neg'] ?? null,
            'prazo' => Tempo::local($c['prazo_em']), 'prazo_data' => $c['prazo_em'] ? (new \DateTimeImmutable($c['prazo_em'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(Tempo::FUSO))->format('Y-m-d') : null,
            'criado_em' => Tempo::local($c['criado_em']), 'concluido_em' => Tempo::local($c['concluido_em']),
            'encerrado_em' => Tempo::local($c['encerrado_em']), 'motivo_encerramento' => $c['motivo_encerramento'],
            'finalizacao' => ['status' => $c['finalizacao_status'], 'tentativas' => (int) $c['finalizacao_tentativas'], 'erro' => $c['finalizacao_erro']],
            'versao_atual_id' => $c['versao_atual_id'] ? (int) $c['versao_atual_id'] : null,
            'versoes' => $versoes, 'participantes' => $participantes, 'arquivos_finais' => $finais,
            'eventos' => $eventosTodos, 'cadeia' => Eventos::verificar($id),
            'marcos' => [
                'criado'        => Tempo::local($c['criado_em']),
                'documento'     => $primeiroEvento('documento_anexado'),
                'participantes' => count($obrig),
                'enviado'       => Tempo::local($abertaEm),
                'assinaturas'   => count($assinaram),
                'concluido'     => Tempo::local($c['concluido_em']),
                'encerrado'     => in_array($c['status'], ['recusado', 'expirado', 'cancelado'], true) ? Tempo::local($c['encerrado_em']) : null,
            ],
            'verificacao_url' => Convite::enderecoBase($accountId) . '/verificar?c=' . $c['codigo_verificacao'],
        ];
    }

    /** Os passos de um participante na versão em assinatura, na ordem em que acontecem. */
    private static function passosDoParticipante(array $ev): array
    {
        $q = fn(string $t) => $ev[$t] ?? null;
        $enviado = $q('convite_enviado');
        $gerado = $q('convite_reemitido') ?? $q('convite_emitido');
        $acesso = $q('acesso');
        $fim = $q('assinado') ?? $q('recusado');
        return [
            ['chave' => 'enviado', 'rotulo' => 'Convite enviado', 'quando' => $enviado['ultimo'] ?? null,
             'obs' => $enviado ? null : ($q('convite_nao_enviado') ? 'O WhatsApp não aceitou o envio' : ($gerado ? 'Link gerado em ' . $gerado['ultimo'] . ', não mandado pelo WhatsApp' : null))],
            ['chave' => 'abriu', 'rotulo' => 'Abriu o link', 'quando' => $acesso['primeiro'] ?? null,
             'obs' => ($acesso['vezes'] ?? 0) > 1 ? 'Abriu ' . $acesso['vezes'] . ' vezes, a última em ' . $acesso['ultimo'] : null],
            ['chave' => 'confirmou', 'rotulo' => 'Confirmou o código', 'quando' => $q('codigo_confirmado')['ultimo'] ?? null,
             'obs' => $q('codigo_incorreto') ? 'Errou o código ' . $q('codigo_incorreto')['vezes'] . ' vez(es)' : null],
            ['chave' => 'viu', 'rotulo' => 'Viu o documento', 'quando' => $q('documento_visualizado')['primeiro'] ?? null, 'obs' => null],
            ['chave' => 'assinou', 'rotulo' => $q('recusado') ? 'Recusou' : 'Assinou', 'quando' => $fim['ultimo'] ?? null,
             'obs' => $q('cpf_divergente') ? 'CPF diferente do esperado: em revisão' : null, 'recusou' => (bool) $q('recusado')],
        ];
    }

    public static function nomeDaMarca(int $accountId): string
    {
        $st = Database::getConnection()->prepare('SELECT * FROM accounts WHERE id = ?');
        $st->execute([$accountId]);
        $acc = $st->fetch(\PDO::FETCH_ASSOC);
        return $acc ? (string) (Marca::daConta($acc)['nome'] ?? $acc['nome'] ?? '') : '';
    }
}
