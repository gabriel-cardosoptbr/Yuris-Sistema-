<?php
// Configuração do banco de dados.
// Tenta ler de .env primeiro (se existir); senão, usa defaults pra dev local (XAMPP).
//
// PRODUÇÃO: criar um .env na raiz com as credenciais reais.
// Veja .env.example pra referência.

require_once __DIR__ . '/../app/bootstrap.php';

\App\Core\EnvLoader::load();

return [
    'host'    => \App\Core\EnvLoader::get('DB_HOST',    '127.0.0.1'),
    'dbname'  => \App\Core\EnvLoader::get('DB_NAME',    'sistema_vendas'),
    'user'    => \App\Core\EnvLoader::get('DB_USER',    'root'),
    'pass'    => \App\Core\EnvLoader::get('DB_PASS',    ''),
    'charset' => \App\Core\EnvLoader::get('DB_CHARSET', 'utf8mb4'),
];
