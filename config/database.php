<?php
// Configuração do banco de dados.
// Tenta ler de .env primeiro (se existir); senão, usa defaults pra dev local (XAMPP).
//
// PRODUÇÃO: criar um .env na raiz com as credenciais reais.
// Veja .env.example pra referência.

require_once __DIR__ . '/../app/Helpers/EnvLoader.php';

\App\Helpers\EnvLoader::load();

return [
    'host'    => \App\Helpers\EnvLoader::get('DB_HOST',    '127.0.0.1'),
    'dbname'  => \App\Helpers\EnvLoader::get('DB_NAME',    'sistema_vendas'),
    'user'    => \App\Helpers\EnvLoader::get('DB_USER',    'root'),
    'pass'    => \App\Helpers\EnvLoader::get('DB_PASS',    ''),
    'charset' => \App\Helpers\EnvLoader::get('DB_CHARSET', 'utf8mb4'),
];
