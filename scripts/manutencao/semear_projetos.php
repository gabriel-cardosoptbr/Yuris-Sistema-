<?php
/**
 * scripts/manutencao/semear_projetos.php — carrega um dossiê de projetos (JSON)
 * na aba Projetos de uma conta (App\Projetos\Projeto::importar).
 *
 * O JSON tem duas listas, "servidores" e "projetos" (cada projeto com "itens":
 * receitas e custos; "servidor" é o NOME de um servidor da lista). O dossiê da
 * Inovaize mora fora do repositório (dado de negócio não é código; regra 4 do
 * CLAUDE.md), em ~/Documents/Claude/Projects/inovaize-dossie-projetos/.
 *
 * Idempotente e conservador: não duplica servidor (casa pelo nome), projeto
 * (pelo slug) nem item (natureza + categoria + descrição), e num registro que já
 * existe só completa campo vazio. Nunca sobrescreve o que alguém preencheu na
 * tela. Só confere por padrão.
 *
 * USO
 *   php scripts/manutencao/semear_projetos.php --conta=119 --arquivo=/caminho/dossie.json             (confere)
 *   php scripts/manutencao/semear_projetos.php --conta=119 --arquivo=/caminho/dossie.json --aplicar   (grava)
 *
 * Em produção, o JSON entra no container pela entrada padrão:
 *   docker exec -i yuris_app php /var/www/html/scripts/manutencao/semear_projetos.php --conta=119 --arquivo=- < dossie.json
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Master\Account;
use App\Projetos\Projeto;

$o = getopt('', ['conta:', 'arquivo:', 'aplicar']);
$conta = (int) ($o['conta'] ?? 0);
$arquivo = (string) ($o['arquivo'] ?? '');
if ($conta <= 0 || $arquivo === '') exit("Informe --conta=ID e --arquivo=dossie.json (ou --arquivo=- para ler da entrada).\n");

$bruto = $arquivo === '-' ? stream_get_contents(STDIN) : @file_get_contents($arquivo);
if ($bruto === false || $bruto === '') exit("Não consegui ler $arquivo.\n");
$dossie = json_decode((string) $bruto, true);
if (!is_array($dossie)) exit("JSON inválido: " . json_last_error_msg() . "\n");

$acc = Account::findById($conta);
if (!$acc) exit("Conta $conta não existe.\n");
echo "conta #{$acc['id']} \"{$acc['nome']}\"; aba Projetos " . (Projeto::habilitado($acc) ? 'ligada' : 'DESLIGADA (ligue com ligar_projetos.php)') . "\n";
$aplicar = isset($o['aplicar']);
echo $aplicar ? "APLICANDO\n\n" : "CONFERÊNCIA: nada será gravado\n\n";

$pdo = \App\Core\Database::getConnection();
$pdo->beginTransaction();
try {
    foreach (Projeto::importar($pdo, $conta, $dossie, $aplicar) as $l) echo $l, "\n";
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    exit("\nERRO, nada gravado: " . $e->getMessage() . "\n");
}
echo $aplicar ? "\npronto.\n" : "\n(conferência) rode de novo com --aplicar.\n";
