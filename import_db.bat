@echo off
REM import_db.bat - cria database, importa schema e executa seed_admin.php
REM Execute este arquivo como Administrador (clique direito > Executar como administrador)

setlocal

set MYSQL_BIN=C:\xampp\mysql\bin\mysql.exe
if not exist "%MYSQL_BIN%" (
  where mysql >nul 2>&1
  if %ERRORLEVEL%==0 (
    for /f "delims=" %%i in ('where mysql') do set MYSQL_BIN=%%i & goto found_mysql
  )
  echo MySQL nao encontrado em C:\xampp\mysql\bin nem no PATH. Ajuste o caminho no arquivo e tente novamente.
  pause
  exit /b 1
)
:found_mysql
echo Usando %MYSQL_BIN%

echo Criando database sistema_vendas (se ja existir, nada acontece)...
"%MYSQL_BIN%" -u root -p -e "CREATE DATABASE IF NOT EXISTS sistema_vendas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if %ERRORLEVEL% neq 0 (
  echo ERRO ao criar database. Verifique credenciais/servico MySQL.
  pause
  exit /b 1
)

echo Importando schema para a database sistema_vendas...
"%MYSQL_BIN%" -u root -p sistema_vendas < "%~dp0database\schema.sql"
if %ERRORLEVEL% neq 0 (
  echo ERRO ao importar schema. Verifique o arquivo database\schema.sql e permissoes.
  pause
  exit /b 1
)

echo Detectando PHP...
set PHP_BIN=C:\xampp\php\php.exe
if not exist "%PHP_BIN%" (
  where php >nul 2>&1
  if %ERRORLEVEL%==0 (
    for /f "delims=" %%p in ('where php') do set PHP_BIN=%%p & goto found_php
  )
  echo PHP nao encontrado em C:\xampp\php nem no PATH. Ajuste o caminho ou instale o PHP.
  pause
  exit /b 1
)
:found_php
echo Usando %PHP_BIN%

echo Rodando seed_admin.php via PHP CLI para criar/atualizar admin...
pushd "%~dp0"
"%PHP_BIN%" scripts/seed_admin.php
if %ERRORLEVEL% neq 0 (
  echo ERRO ao executar scripts/seed_admin.php. Verifique se o PHP esta no PATH ou se o caminho do XAMPP esta correto.
  popd
  pause
  exit /b 1
)
popd
echo Concluido. Abra a aplicacao no navegador e teste.
pause
endlocal
