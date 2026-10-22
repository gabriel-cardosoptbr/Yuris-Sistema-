<?php
/**
 * scripts/manutencao/dono_por_conversa.php: liga o "dono por conversa" num número
 * de WhatsApp atendido por mais de um vendedor (App\WhatsAppAgente\DonoDaConversa,
 * migration 164). Pedido da Inovaize em 22/10/2026: a Isa e o Kauê no mesmo
 * número; o que já existia é da Isa, o que entra de hoje em diante é do Kauê.
 *
 * Liga o modo com o corte (--desde), quem fica com as conversas de antes
 * (--antigas) e quem recebe as novas (--novas), e distribui as conversas que
 * ainda não têm dono pela data em que foram criadas. Conversa que já tem dono
 * não muda. Os usuários têm de ser ativos da conta do número. Só confere por
 * padrão; --aplicar grava. --desligar volta o número ao normal (os donos
 * gravados nas conversas ficam, como responsável).
 *
 * USO
 *   php scripts/manutencao/dono_por_conversa.php --instancia=18 --desde="2026-10-22 00:00:00" --antigas=126 --novas=128
 *   php scripts/manutencao/dono_por_conversa.php --instancia=18 --desde="2026-10-22 00:00:00" --antigas=126 --novas=128 --aplicar
 *   php scripts/manutencao/dono_por_conversa.php --instancia=18 --novas=126 --aplicar      (troca só quem recebe as novas)
 *   php scripts/manutencao/dono_por_conversa.php --instancia=18 --desligar --aplicar
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\WhatsAppAgente\DonoDaConversa;

$o = getopt('', ['instancia:', 'desde:', 'antigas:', 'novas:', 'desligar', 'aplicar']);
$inst = (int) ($o['instancia'] ?? 0);
$aplicar = isset($o['aplicar']);
if ($inst <= 0) exit("Informe --instancia=ID.\n");

$pdo = \App\Core\Database::getConnection();
$st = $pdo->prepare('SELECT id, account_id, instance_name, phone, dono_conversa_desde, dono_novas_user_id, dono_antigas_user_id FROM whatsapp_instances WHERE id = ?');
try { $st->execute([$inst]); } catch (\Throwable $e) { exit("Rode a migration 164 antes.\n"); }
$n = $st->fetch(PDO::FETCH_ASSOC);
if (!$n) exit("Número $inst não existe.\n");
$nome = function (?int $id) use ($pdo): string {
    if (!$id) return 'ninguém';
    $s = $pdo->prepare('SELECT nome FROM users WHERE id = ?'); $s->execute([$id]);
    return '#' . $id . ' ' . ($s->fetchColumn() ?: '?');
};
echo "número #{$n['id']} \"{$n['instance_name']}\" ({$n['phone']}), conta {$n['account_id']}\n";
echo "agora: " . ($n['dono_conversa_desde'] ? "modo ligado desde {$n['dono_conversa_desde']}, antigas para " . $nome($n['dono_antigas_user_id'] ? (int) $n['dono_antigas_user_id'] : null) . ', novas para ' . $nome($n['dono_novas_user_id'] ? (int) $n['dono_novas_user_id'] : null) : 'modo desligado') . "\n";

if (isset($o['desligar'])) {
    echo "vai: desligar o modo\n";
    if ($aplicar) { DonoDaConversa::configurar($pdo, $inst, null, null, null); echo "Desligado.\n"; }
    else echo "Só conferido. Rode com --aplicar para gravar.\n";
    exit;
}

$desde   = (string) ($o['desde'] ?? ($n['dono_conversa_desde'] ?? ''));
$antigas = isset($o['antigas']) ? ((int) $o['antigas'] ?: null) : ($n['dono_antigas_user_id'] ? (int) $n['dono_antigas_user_id'] : null);
$novas   = isset($o['novas']) ? ((int) $o['novas'] ?: null) : ($n['dono_novas_user_id'] ? (int) $n['dono_novas_user_id'] : null);
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $desde)) exit("Informe --desde=\"AAAA-MM-DD HH:MM:SS\".\n");
echo "vai: modo desde $desde, antigas para " . $nome($antigas) . ', novas para ' . $nome($novas) . "\n";

$pdo->beginTransaction();
try {
    DonoDaConversa::configurar($pdo, $inst, $desde, $novas, $antigas);
    $r = DonoDaConversa::distribuirSemDono($pdo, $inst, true);
    echo "conversas sem dono: {$r['antigas']} de antes do corte (para " . $nome($antigas) . "), {$r['novas']} de depois (para " . $nome($novas) . ")\n";
    if ($aplicar) { $pdo->commit(); echo "Gravado.\n"; }
    else { $pdo->rollBack(); echo "Só conferido. Rode com --aplicar para gravar.\n"; }
} catch (\Throwable $e) {
    $pdo->rollBack();
    exit('Nada feito: ' . $e->getMessage() . "\n");
}
