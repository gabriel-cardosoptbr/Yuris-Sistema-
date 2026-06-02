<?php
/**
 * _json_guard.php — Blindagem de saída para endpoints de API que devolvem JSON.
 *
 * PROBLEMA QUE RESOLVE:
 *   Quando o PHP roda com display_errors=On (caso de produção sem php.ini, que cai
 *   no default compilado On), QUALQUER Notice/Warning/Deprecated é injetado como
 *   HTML no corpo da resposta (ex.: "<br /><b>Warning</b>: ..."). Como o endpoint
 *   já enviou "Content-Type: application/json" e o status segue 200, o frontend
 *   recebe um corpo que começa com "<" e o JSON.parse quebra com:
 *       "Unexpected token '<', "..." is not valid JSON"
 *   Foi exatamente o que derrubou a aba Tarefas (loadBoards → "Falha ao carregar
 *   quadros"), de forma intermitente (só quando algum aviso disparava na request).
 *
 * O QUE FAZ:
 *   - display_errors = Off  → avisos do PHP NUNCA entram no corpo da resposta.
 *   - log_errors    = On    → o erro continua registrado no log do servidor
 *                             (stderr do container / error_log), pra diagnóstico.
 *   Não silencia exceções nem altera lógica: só impede o "vazamento" visual que
 *   corrompe o JSON. Defesa em profundidade — independe do php.ini do ambiente.
 *
 * USO:
 *   require_once __DIR__ . '/_json_guard.php';   // 1ª linha do endpoint JSON
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
