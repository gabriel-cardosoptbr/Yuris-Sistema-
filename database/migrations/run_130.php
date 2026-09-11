<?php
/**
 * Migration 130 — a central de notificações passa a receber TUDO.
 *
 * ---------------------------------------------------------------------------
 * O QUE FOI MEDIDO ANTES
 * ---------------------------------------------------------------------------
 * O sino já existia e funcionava, mas era alimentado por quase nada: no sistema
 * inteiro, apenas SETE lugares criavam notificação (monitoramento DJEN e AASP,
 * handoff do agente, vínculo de conta, vínculo de advogado e compartilhamento
 * de recurso).
 *
 * Tudo o mais era silencioso. Card mudou de etapa, cliente cadastrado, processo
 * alterado, prazo criado, tarefa concluída: ZERO aviso. E os quatro lugares que
 * guardam responsável (tarefa, prospecção, cliente, processo) não avisavam a
 * pessoa que acabara de virar responsável.
 *
 * O chat interno já tinha menção pronta, com tabela própria, aceitando mencionar
 * usuário, processo, card e cliente. E NINGUÉM era avisado ao ser mencionado.
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA DE "TUDO TEM QUE CHEGAR"
 * ---------------------------------------------------------------------------
 * Num escritório ativo, "absolutamente tudo" dá entre 100 e 300 avisos por dia.
 * Sino com 300 itens vira sino ignorado, e aí o aviso que importava, o prazo que
 * vence amanhã, some junto com o resto.
 *
 * Por isso a coluna `natureza`, que é o coração desta migration:
 *
 *   dirigido   É PRA VOCÊ. Virou responsável, foi mencionado, seu prazo chegou.
 *              Conta no contador vermelho.
 *   movimento  Aconteceu no escritório. Chega, fica no sino, mas NÃO estoura o
 *              contador. É acompanhamento, não cobrança.
 *
 * A mesma caixa, dois pesos. Sem isso, atender ao pedido literal destruiria a
 * utilidade do próprio sino.
 *
 * ---------------------------------------------------------------------------
 * NÃO APAGA E NÃO ALTERA NENHUMA LINHA EXISTENTE. IDEMPOTENTE.
 * ---------------------------------------------------------------------------
 * As notificações que já existem ficam como `dirigido`, que é o comportamento
 * atual delas: todas as sete origens antigas são avisos de fato dirigidos a
 * alguém.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_130.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_130.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function temTabela(\PDO $pdo, string $t): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (bool) $st->fetchColumn();
}

function temColuna(\PDO $pdo, string $t, string $c): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    return (bool) $st->fetchColumn();
}

function temIndice(\PDO $pdo, string $t, string $i): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$t, $i]);
    return (bool) $st->fetchColumn();
}

linha('== Migration 130: a central de notificacoes passa a receber tudo ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$feitas = 0;
$puladas = 0;

/* ---------------------------------------------------------------------------
 * 1. As colunas novas de account_notifications
 * ------------------------------------------------------------------------- */
$colunas = [
    'natureza' => "ALTER TABLE `account_notifications`
        ADD COLUMN `natureza` VARCHAR(12) NOT NULL DEFAULT 'dirigido'
        COMMENT 'dirigido = e pra voce, conta no badge | movimento = aconteceu no escritorio, nao conta'
        AFTER `tipo`",

    'entidade' => "ALTER TABLE `account_notifications`
        ADD COLUMN `entidade` VARCHAR(20) NULL
        COMMENT 'cliente|card|processo|tarefa|chat: sobre O QUE e o aviso'
        AFTER `natureza`",

    'entidade_id' => "ALTER TABLE `account_notifications`
        ADD COLUMN `entidade_id` INT NULL
        COMMENT 'id do registro, para o clique levar ao lugar certo'
        AFTER `entidade`",

    /*
     * Quem CAUSOU. A regra mais importante contra ruido depende desta coluna:
     * ninguem e notificado do que ele mesmo acabou de fazer. Sem ela, cada
     * pessoa receberia um aviso a cada clique proprio.
     */
    'origem_user_id' => "ALTER TABLE `account_notifications`
        ADD COLUMN `origem_user_id` INT NULL
        COMMENT 'quem causou o evento; ninguem e avisado do proprio ato'
        AFTER `entidade_id`",

    'url' => "ALTER TABLE `account_notifications`
        ADD COLUMN `url` VARCHAR(255) NULL
        COMMENT 'para onde o clique leva'
        AFTER `mensagem`",

    /*
     * Dedupe. Mover um card tres vezes em dois minutos e UM movimento, nao tres
     * avisos. A chave e montada pelo codigo (ver App\Notificacoes\Aviso) e o
     * indice e o que torna a consulta de dedupe barata.
     */
    'chave_dedupe' => "ALTER TABLE `account_notifications`
        ADD COLUMN `chave_dedupe` VARCHAR(120) NULL
        COMMENT 'o mesmo aviso repetido em janela curta vira um so'
        AFTER `payload`",
];

foreach ($colunas as $nome => $ddl) {
    if (temColuna($pdo, 'account_notifications', $nome)) {
        linha("  [ja existe] account_notifications.$nome");
        $puladas++;
        continue;
    }
    if ($dryRun) {
        linha("  [criaria]   account_notifications.$nome");
    } else {
        $pdo->exec($ddl);
        linha("  [criada]    account_notifications.$nome");
    }
    $feitas++;
}

/* ---------------------------------------------------------------------------
 * 2. Indices
 * ------------------------------------------------------------------------- */
$indices = [
    // A consulta do sino: as minhas + as da conta, por natureza, nao lidas,
    // mais recentes primeiro. Sem este indice ela vira varredura assim que a
    // tabela passar de alguns milhares de linhas, e ela VAI passar agora que
    // todo movimento gera linha.
    'idx_notif_caixa' => "ALTER TABLE `account_notifications`
        ADD INDEX `idx_notif_caixa` (`account_id`, `user_id`, `natureza`, `lida`, `created_at`)",

    'idx_notif_dedupe' => "ALTER TABLE `account_notifications`
        ADD INDEX `idx_notif_dedupe` (`chave_dedupe`, `created_at`)",

    'idx_notif_entidade' => "ALTER TABLE `account_notifications`
        ADD INDEX `idx_notif_entidade` (`entidade`, `entidade_id`)",
];

foreach ($indices as $nome => $ddl) {
    if (temIndice($pdo, 'account_notifications', $nome)) {
        linha("  [ja existe] indice $nome");
        $puladas++;
        continue;
    }
    if ($dryRun) {
        linha("  [criaria]   indice $nome");
    } else {
        $pdo->exec($ddl);
        linha("  [criado]    indice $nome");
    }
    $feitas++;
}

/* ---------------------------------------------------------------------------
 * 3. Preferencias por pessoa
 * ------------------------------------------------------------------------- */
/*
 * TUDO NASCE LIGADO. A tabela guarda o DESVIO do padrao, nao o padrao.
 *
 * Foi decisao consciente: chave ausente significa ligado. Assim a entrega nao
 * depende de ninguem marcar caixinha, e quem achar barulhento desliga. O
 * caminho contrario (nascer desligado) faria o escritorio inteiro concluir que
 * o modulo nao funciona, que e o mesmo erro que a permissao `relatorios`
 * evitou.
 */
$ddlPref = "
CREATE TABLE `notificacao_preferencias` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `user_id`    INT NOT NULL,
  `chave`      VARCHAR(40) NOT NULL COMMENT 'movimento|responsavel|mencao|prazo',
  `ativo`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pref_user_chave` (`user_id`, `chave`),
  KEY `idx_pref_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Guarda so o DESVIO do padrao: chave ausente = ligado'";

if (temTabela($pdo, 'notificacao_preferencias')) {
    linha('  [ja existe] tabela notificacao_preferencias');
    $puladas++;
} else {
    if ($dryRun) {
        linha('  [criaria]   tabela notificacao_preferencias');
    } else {
        $pdo->exec($ddlPref);
        linha('  [criada]    tabela notificacao_preferencias');
    }
    $feitas++;
}

/* ---------------------------------------------------------------------------
 * Diagnostico
 * ------------------------------------------------------------------------- */
linha();
$total = (int) $pdo->query('SELECT COUNT(*) FROM account_notifications')->fetchColumn();
$naoLidas = (int) $pdo->query('SELECT COUNT(*) FROM account_notifications WHERE lida = 0')->fetchColumn();
linha("  notificacoes existentes:  $total   (nao lidas: $naoLidas)");
linha('  todas continuam como "dirigido", que e o comportamento atual delas');

linha();
linha("  alteracoes: $feitas   ja existentes: $puladas");
linha($dryRun ? '  DRY-RUN concluido. Rode sem --dry-run para aplicar.' : '  Migration 130 aplicada.');
