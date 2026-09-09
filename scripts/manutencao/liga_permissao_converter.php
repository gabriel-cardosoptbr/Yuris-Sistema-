<?php
/**
 * liga_permissao_converter.php — concede 'prospeccao.converter_cliente' a quem
 * já existe.
 *
 * POR QUE PRECISA DISTO
 *
 * A permissão nasceu junto com o botão "Tornar cliente" (09/09/2026). Owner e
 * admin já passam pelo curinga '*' que o AuthController grava na sessão, então
 * para eles nada muda. Quem fica de fora é o usuário com LISTA EXPLÍCITA de
 * permissões: ele não tem a chave nova, e o botão simplesmente não aparece.
 *
 * A decisão foi que converter é parte do trabalho normal de quem usa a
 * Prospecção. A tela de Usuários já cria usuário novo com a caixinha marcada;
 * este script cobre os que já existiam.
 *
 * O QUE ELE FAZ, E O QUE NÃO FAZ
 *
 *   · concede SÓ a quem recebe lista explícita na sessão, ou seja
 *     perfil <> 'admin' E role fora de (owner, admin). Para os demais a linha
 *     seria inerte, e linha inerte em tabela de permissão é ruído que confunde
 *     auditoria depois
 *   · NÃO remove nada, NÃO mexe em nenhuma outra permissão
 *   · é idempotente: rodar de novo não duplica (INSERT IGNORE + checagem)
 *
 * Uso local: C:\xampp\php\php.exe scripts/manutencao/liga_permissao_converter.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/scripts/manutencao/liga_permissao_converter.php --dry-run
 *
 * Flags:
 *   --dry-run   diz o que faria, sem gravar. RODE ISSO PRIMEIRO EM PRODUÇÃO.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Prospeccao\ConversaoCliente;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$CHAVE = ConversaoCliente::PERMISSAO;

function linha(string $s = ''): void { echo $s . PHP_EOL; }

linha('== Permissão "Tornar cliente" para usuários existentes ==');
linha('   chave: ' . $CHAVE);
linha($dryRun ? '   MODO DRY-RUN: nada será gravado.' : '   MODO APLICAÇÃO.');
linha();

/*
 * Espelha exatamente a regra do AuthController: curinga para role owner/admin
 * OU perfil 'admin'. Quem não cai nisso recebe lista explícita, e é quem precisa
 * da chave. Se a regra do login mudar um dia, esta consulta muda junto.
 */
$usuarios = $pdo->query(
    "SELECT u.id, u.nome, u.perfil, u.role, u.account_id,
            (SELECT COUNT(*) FROM user_permissions p
              WHERE p.user_id = u.id AND p.page = 'prospeccao.converter_cliente') ja_tem,
            (SELECT COUNT(*) FROM user_permissions p WHERE p.user_id = u.id) total_perms
       FROM users u
      WHERE COALESCE(u.perfil,'') <> 'admin'
        AND COALESCE(u.role,'') NOT IN ('owner','admin')
   ORDER BY u.account_id, u.id"
)->fetchAll(\PDO::FETCH_ASSOC);

$curinga = (int) $pdo->query(
    "SELECT COUNT(*) FROM users u
      WHERE COALESCE(u.perfil,'') = 'admin'
         OR COALESCE(u.role,'') IN ('owner','admin')"
)->fetchColumn();

linha("  usuários que já convertem pelo curinga (owner/admin): $curinga");
linha("  usuários com lista explícita, que precisam da chave: " . count($usuarios));
linha();

if ($usuarios === []) {
    linha('  Nada a fazer.');
    exit(0);
}

$ins = $pdo->prepare('INSERT IGNORE INTO user_permissions (user_id, page) VALUES (?, ?)');
$concedidas = 0;
$jaTinham   = 0;

foreach ($usuarios as $u) {
    $rot = sprintf('#%-4s %-26s conta %-4s (%s perms)', $u['id'], substr((string) $u['nome'], 0, 26), $u['account_id'], $u['total_perms']);
    if ((int) $u['ja_tem'] > 0) {
        linha("  [já tem]    $rot");
        $jaTinham++;
        continue;
    }
    if ($dryRun) {
        linha("  [concederia] $rot");
    } else {
        $ins->execute([(int) $u['id'], $CHAVE]);
        linha("  [concedida]  $rot");
    }
    $concedidas++;
}

linha();
linha("  concedidas: $concedidas   já tinham: $jaTinham");

if (!$dryRun) {
    $confere = (int) $pdo->query(
        "SELECT COUNT(*) FROM user_permissions WHERE page = 'prospeccao.converter_cliente'"
    )->fetchColumn();
    linha("  linhas com a chave no banco agora: $confere");
    linha();
    linha('  Quem já estava logado só vê o botão no próximo login: a lista de');
    linha('  permissões é gravada na sessão no momento em que ela nasce.');
}

linha($dryRun ? '  DRY-RUN concluído. Rode sem --dry-run para aplicar.' : '  Concluído.');
