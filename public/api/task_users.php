<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

// Carrega contexto de tenant (aborta 401 se sessão inválida)
$ctx = AccountContext::fromSession();

// Usuários acessíveis (matriz + filiais vinculadas que mantêm o módulo 'tarefas'
// ligado), já com metadados {account_id, account_nome, account_tipo} pra
// suportar o agrupamento Matriz/Filial nos <select> via Yuris.populateUserSelect.
// Passar 'tarefas' garante que filiais com o sync deste módulo desligado fiquem
// de fora — mesmo comportamento do antigo getAccessibleAccountIds('tarefas').
$users = $ctx->getAccessibleUsers(true, 'tarefas');

echo json_encode(['ok' => true, 'data' => $users]);
