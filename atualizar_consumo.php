<?php
// ============================================================
//  ATUALIZAR CMM/CMD - Relatório de Consumo Mensal
//  C:\xampp\htdocs\hma\atualizar_consumo.php
//
//  Como usar:
//  1. Abra a planilha no Excel
//  2. Salvar Como → CSV UTF-8 (separado por vírgulas)
//  3. Importe o .csv aqui
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'Biel231202#');
define('DB_NAME', 'estoque_hospital');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['erro'=>'Envie o arquivo via POST']); exit;
}
if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['erro'=>'Arquivo não recebido']); exit;
}

$tmp  = $_FILES['arquivo']['tmp_name'];
$nome = $_FILES['arquivo']['name'];
$ext  = strtolower(pathinfo($nome, PATHINFO_EXTENSION));

if (!in_array($ext, ['csv','txt'])) {
    echo json_encode(['erro'=>'Por favor, salve a planilha como CSV antes de importar. No Excel: Arquivo → Salvar Como → CSV UTF-8']); exit;
}

// ── Ler CSV ───────────────────────────────────────────────
$handle = fopen($tmp, 'r');
// Detectar separador
$primeira = fgets($handle); rewind($handle);
$sep = (substr_count($primeira, ';') > substr_count($primeira, ',')) ? ';' : ',';

// Ler todas as linhas
$todasLinhas = [];
while (($row = fgetcsv($handle, 0, $sep)) !== false) {
    $todasLinhas[] = $row;
}
fclose($handle);

if (empty($todasLinhas)) {
    echo json_encode(['erro'=>'Arquivo CSV vazio']); exit;
}

// Encontrar linha de cabeçalho e colunas de meses
$headerIdx = -1;
$mesCols   = [];
$codCol    = 0;

foreach ($todasLinhas as $i => $row) {
    $a = mb_strtoupper(trim($row[0] ?? ''), 'UTF-8');
    if (preg_match('/C[ÓO]DIGO/u', $a)) {
        $headerIdx = $i;
        break;
    }
    // Procurar colunas de meses (MM/AAAA) nesta linha
    foreach ($row as $j => $v) {
        if (preg_match('/\d{2}\/\d{4}/', trim($v))) {
            $mesCols[] = $j;
        }
    }
}

// Fallback
if ($headerIdx < 0) $headerIdx = 2; // linha 3 (índice 2)
if (empty($mesCols)) $mesCols = [6, 8, 10, 12]; // G,I,K,M

$codPat = '/^\d{2}\.\d{2}\.\d{3}\.\d$/';
$produtos = [];

for ($i = $headerIdx + 1; $i < count($todasLinhas); $i++) {
    $row = $todasLinhas[$i];
    $cod = trim($row[$codCol] ?? '');
    if (!preg_match($codPat, $cod)) continue;

    $qtds = [];
    foreach ($mesCols as $col) {
        $v = trim($row[$col] ?? '0');
        $v = preg_replace('/[^0-9.,]/', '', $v);
        $v = str_replace(',', '.', $v);
        $qtds[] = max(0, (int)floatval($v));
    }

    if (count($qtds) < 2) continue;

    // Excluir menor, média dos restantes
    sort($qtds);
    array_shift($qtds);
    $cmm = (int)round(array_sum($qtds) / count($qtds));
    $cmd = round($cmm / 30, 2);

    $produtos[$cod] = ['cmm' => $cmm, 'cmd' => $cmd];
}

if (empty($produtos)) {
    echo json_encode(['erro'=>'Nenhum produto encontrado. Verifique se o arquivo é o CSV do relatório de consumo.']); exit;
}

// ── Atualizar MySQL ────────────────────────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');
if ($conn->connect_error) { echo json_encode(['erro'=>'MySQL: '.$conn->connect_error]); exit; }

$ok = $nok = 0;
foreach ($produtos as $cod => $p) {
    $stmt = $conn->prepare(
        "UPDATE produtos SET 
            cmm = ?,
            cmd = ?,
            saldo_dias = CASE WHEN ? > 0 AND estoque_total > 0 
                         THEN ROUND(estoque_total / ?) 
                         ELSE saldo_dias END,
            atualizado_em = CURRENT_TIMESTAMP
         WHERE cod_jde = ?"
    );
    $stmt->bind_param('dddds', $p['cmm'], $p['cmd'], $p['cmd'], $p['cmd'], $cod);
    $stmt->execute();
    if ($stmt->affected_rows > 0) $ok++; else $nok++;
    $stmt->close();
}
$conn->close();

echo json_encode([
    'status'          => 'OK',
    'mensagem'        => 'CMM e CMD atualizados com sucesso!',
    'meses_detectados'=> count($mesCols),
    'registros_lidos' => count($produtos),
    'atualizados'     => $ok,
    'nao_encontrados' => $nok,
    'horario'         => date('d/m/Y H:i:s')
], JSON_UNESCAPED_UNICODE);
?>
