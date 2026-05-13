<?php
/**
 * Script de limpeza única — duplicatas de tarefas recorrentes
 * Acesse: http://localhost/sistema_vendas/public/admin/cleanup_recorrencia.php
 * APAGUE este arquivo após usar.
 */
require_once __DIR__ . '/../../app/Models/Database.php';

$pdo = \App\Models\Database::getConnection();

// 1. Busca todas as recorrências com mais de 1 instância ativa
$stmt = $pdo->query("
    SELECT recorrencia_id, COUNT(*) AS total
    FROM tasks
    WHERE recorrencia_id IS NOT NULL AND status != 'arquivada'
    GROUP BY recorrencia_id
    HAVING COUNT(*) > 1
");
$grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($grupos)) {
    echo "<p style='font-family:monospace;color:green'>✓ Nenhuma duplicata encontrada. Tudo limpo!</p>";
    exit;
}

$arquivadas = 0;

foreach ($grupos as $g) {
    $recId = (int)$g['recorrencia_id'];

    // Pega a instância mais recente (maior prazo; em empate, maior id)
    $melhor = $pdo->prepare("
        SELECT id FROM tasks
        WHERE recorrencia_id = ? AND status != 'arquivada'
        ORDER BY prazo DESC, id DESC
        LIMIT 1
    ");
    $melhor->execute([$recId]);
    $keepId = (int)$melhor->fetchColumn();

    if (!$keepId) continue;

    // Arquiva todas as outras instâncias desta recorrência
    $upd = $pdo->prepare("
        UPDATE tasks
        SET status = 'arquivada'
        WHERE recorrencia_id = ?
          AND id != ?
          AND status != 'arquivada'
    ");
    $upd->execute([$recId, $keepId]);
    $arquivadas += $upd->rowCount();
}

echo "<pre style='font-family:monospace'>";
echo "Grupos com duplicata encontrados: " . count($grupos) . "\n";
echo "Instâncias antigas arquivadas:    {$arquivadas}\n";
echo "\n✓ Limpeza concluída. Apague este arquivo agora.\n";
echo "</pre>";
