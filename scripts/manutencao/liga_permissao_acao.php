<?php
/**
 * liga_permissao_acao.php — concede uma permissão de AÇÃO a quem já existe.
 *
 * ---------------------------------------------------------------------------
 * POR QUE ISTO EXISTE
 * ---------------------------------------------------------------------------
 * Permissão de ação nasce junto com a funcionalidade. Owner e admin já passam
 * pelo curinga '*' que o AuthController grava na sessão, então para eles nada
 * muda. Quem fica de fora é o usuário com LISTA EXPLÍCITA: ele não tem a chave
 * nova, e o botão simplesmente não aparece.
 *
 * A tela de Usuários já cria usuário novo com a caixinha marcada. Este script
 * cobre os que já existiam.
 *
 * ---------------------------------------------------------------------------
 * POR QUE ELE É GENÉRICO, E POR QUE MESMO ASSIM TEM WHITELIST
 * ---------------------------------------------------------------------------
 * O `liga_permissao_converter.php` fez isso para uma chave só, e a Fase 2 traz
 * outra. Copiar o arquivo pela terceira vez seria três lugares para corrigir o
 * mesmo bug.
 *
 * Mas ele NÃO aceita chave arbitrária. Um script de manutenção que concede
 * qualquer string viraria uma porta de escalonamento de privilégio: bastaria
 * rodar com --chave=usuarios para dar acesso à tela de usuários a todo mundo.
 * A whitelist abaixo tem só as chaves de AÇÃO, que são as que a tela de Usuários
 * marca por padrão.
 *
 * ---------------------------------------------------------------------------
 * O QUE ELE FAZ, E O QUE NÃO FAZ
 * ---------------------------------------------------------------------------
 *   · concede SÓ a quem recebe lista explícita na sessão (perfil <> 'admin' E
 *     role fora de owner/admin). Para os demais a linha seria inerte, e linha
 *     inerte em tabela de permissão é ruído que confunde auditoria depois
 *   · NÃO remove nada e NÃO mexe em nenhuma outra permissão
 *   · é idempotente: rodar de novo não duplica (INSERT IGNORE + checagem)
 *
 * Uso local: C:\xampp\php\php.exe scripts/manutencao/liga_permissao_acao.php --chave=crm.catalogos_gerenciar --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/scripts/manutencao/liga_permissao_acao.php --chave=crm.catalogos_gerenciar --dry-run
 *
 * Flags:
 *   --chave=X   qual permissão conceder (obrigatório, tem de estar na whitelist)
 *   --dry-run   diz o que faria, sem gravar. RODE ISSO PRIMEIRO EM PRODUÇÃO.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$dryRun = in_array('--dry-run', $argv ?? [], true);

$chave = null;
foreach ($argv ?? [] as $a) {
    if (strncmp($a, '--chave=', 8) === 0) {
        $chave = substr($a, 8);
    }
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Crm\Permissao;
use App\Prospeccao\ConversaoCliente;

/**
 * As chaves de AÇÃO, com o rótulo que a tela de Usuários mostra. Chave de TELA
 * (dashboard, financas, usuarios...) não entra aqui de propósito: conceder tela
 * em lote é decisão de quem administra o escritório, não de script.
 */
$PERMITIDAS = [
    ConversaoCliente::PERMISSAO => 'Tornar cliente (converter prospecção)',
    Permissao::CATALOGOS        => 'Gerenciar etiquetas e campos personalizados',
];

function linha(string $s = ''): void
{
    echo $s . PHP_EOL;
}

if ($chave === null || !isset($PERMITIDAS[$chave])) {
    linha('Informe --chave=<permissão>. Aceitas:');
    foreach ($PERMITIDAS as $k => $rot) {
        linha("  · $k    $rot");
    }
    exit(1);
}

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

linha('== Permissão de ação para usuários existentes ==');
linha('   chave: ' . $chave . '   (' . $PERMITIDAS[$chave] . ')');
linha($dryRun ? '   MODO DRY-RUN: nada será gravado.' : '   MODO APLICAÇÃO.');
linha();

/*
 * Espelha exatamente a regra do AuthController: curinga para role owner/admin OU
 * perfil 'admin'. Quem não cai nisso recebe lista explícita, e é quem precisa da
 * chave. Se a regra do login mudar um dia, esta consulta muda junto.
 */
$usuarios = $pdo->prepare(
    "SELECT u.id, u.nome, u.perfil, u.role, u.account_id,
            (SELECT COUNT(*) FROM user_permissions p
              WHERE p.user_id = u.id AND p.page = :chave) ja_tem,
            (SELECT COUNT(*) FROM user_permissions p WHERE p.user_id = u.id) total_perms
       FROM users u
      WHERE COALESCE(u.perfil,'') <> 'admin'
        AND COALESCE(u.role,'') NOT IN ('owner','admin')
   ORDER BY u.account_id, u.id"
);
$usuarios->execute(['chave' => $chave]);
$usuarios = $usuarios->fetchAll(\PDO::FETCH_ASSOC);

$curinga = (int) $pdo->query(
    "SELECT COUNT(*) FROM users u
      WHERE COALESCE(u.perfil,'') = 'admin'
         OR COALESCE(u.role,'') IN ('owner','admin')"
)->fetchColumn();

linha("  usuários cobertos pelo curinga (owner/admin): $curinga");
linha("  usuários com lista explícita, que precisam da chave: " . count($usuarios));
linha();

if ($usuarios === []) {
    linha('  Nada a fazer.');
    exit(0);
}

$antes = (int) $pdo->query('SELECT COUNT(*) FROM user_permissions')->fetchColumn();

$ins        = $pdo->prepare('INSERT IGNORE INTO user_permissions (user_id, page) VALUES (?, ?)');
$concedidas = 0;
$jaTinham   = 0;

foreach ($usuarios as $u) {
    $rot = sprintf(
        '#%-4s %-26s conta %-4s (%s perms)',
        $u['id'],
        substr((string) $u['nome'], 0, 26),
        $u['account_id'],
        $u['total_perms']
    );
    if ((int) $u['ja_tem'] > 0) {
        linha("  [já tem]     $rot");
        $jaTinham++;
        continue;
    }
    if ($dryRun) {
        linha("  [concederia] $rot");
    } else {
        $ins->execute([(int) $u['id'], $chave]);
        linha("  [concedida]  $rot");
    }
    $concedidas++;
}

linha();
linha("  concedidas: $concedidas   já tinham: $jaTinham");

if (!$dryRun) {
    $comChave = $pdo->prepare('SELECT COUNT(*) FROM user_permissions WHERE page = ?');
    $comChave->execute([$chave]);
    $depois = (int) $pdo->query('SELECT COUNT(*) FROM user_permissions')->fetchColumn();

    linha('  linhas com a chave no banco agora: ' . (int) $comChave->fetchColumn());
    // A diferença tem de ser exatamente o número concedido. Se não for, alguma
    // outra permissão foi tocada, e é isso que este confere provaria.
    linha("  total em user_permissions: $antes -> $depois  (diferença " . ($depois - $antes) . ')');
    linha();
    linha('  Quem já estava logado só vê a mudança no próximo login: a lista de');
    linha('  permissões é gravada na sessão no momento em que ela nasce.');
}

linha($dryRun ? '  DRY-RUN concluído. Rode sem --dry-run para aplicar.' : '  Concluído.');
