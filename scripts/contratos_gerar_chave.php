<?php
/**
 * Gera a chave Ed25519 do selo dos contratos (App\Contratos\Manifesto) e grava a
 * chave PRIVADA num arquivo fora do banco e fora do repositório. Rodar uma vez por
 * servidor. Não sobrescreve arquivo existente: trocar a chave é decisão à parte
 * (manifestos antigos continuam conferíveis só com a chave pública antiga, que
 * deve ser guardada; o chave_id de cada manifesto diz qual foi usada).
 *
 * Uso: php scripts/contratos_gerar_chave.php /caminho/fora/do/repo/contratos_ed25519.key
 * Depois: no .env, CONTRATOS_CHAVE_ASSINATURA=/caminho/fora/do/repo/contratos_ed25519.key
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../app/bootstrap.php';

$arq = $argv[1] ?? '';
if ($arq === '') { fwrite(STDERR, "Informe o caminho do arquivo da chave.\n"); exit(1); }
if (file_exists($arq)) { fwrite(STDERR, "Já existe $arq. Nada feito.\n"); exit(1); }
$raiz = realpath(__DIR__ . '/..');
$dir = realpath(dirname($arq)) ?: dirname($arq);
if ($raiz && str_starts_with(str_replace('\\', '/', $dir), str_replace('\\', '/', $raiz))) {
    fwrite(STDERR, "A chave não pode ficar dentro do repositório.\n");
    exit(1);
}
$par = \App\Contratos\Cripto::gerarParDeChaves();
$antes = umask(0077);
$ok = file_put_contents($arq, $par['secreta'] . "\n", LOCK_EX);
umask($antes);
if ($ok === false) { fwrite(STDERR, "Não foi possível gravar $arq.\n"); exit(1); }
@chmod($arq, 0400);
echo "Chave gravada em $arq (só leitura).\n";
echo "Chave pública (guarde junto da documentação): {$par['publica']}\n";
echo "chave_id: {$par['chave_id']}\n";
echo "No .env: CONTRATOS_CHAVE_ASSINATURA=$arq\n";
