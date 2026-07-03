<?php
// ============================================================
//  API - Almoxarifado HMA - Hospital do Andaraí
//  Salvar em: C:\wamp64\www\hma\api_produto.php
//  URL de acesso: http://localhost/hma/api_produto.php?cod=02.14.001.1
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: ngrok-skip-browser-warning, Accept, Content-Type');

// Responder preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Configuração do banco ──────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // seu usuário MySQL
define('DB_PASS', 'Biel231202#');  // senha MySQL
define('DB_NAME', 'estoque_hospital');

// ── Receber código do produto ──────────────────────────────
$cod = trim($_GET['cod'] ?? '');

if (empty($cod)) {
    echo json_encode(['erro' => 'Parâmetro ?cod= não informado']);
    exit;
}

// ── Conectar ao MySQL ──────────────────────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro de conexão: ' . $conn->connect_error]);
    exit;
}

// ── Consultar produto ──────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM produtos WHERE cod_jde = ? LIMIT 1");
$stmt->bind_param('s', $cod);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['erro' => "Código \"$cod\" não encontrado"]);
    exit;
}

$p = $result->fetch_assoc();
$stmt->close();

// ── Montar resposta ────────────────────────────────────────
echo json_encode([
    'cod'                   => $p['cod_jde'],
    'produto'               => $p['produto'],
    'grupo'                 => $p['grupo'],
    'tipo'                  => $p['tipo_compra'],
    'estoque_pvax'          => (int)$p['estoque_pvax'],
    'almoxarifado'          => (int)$p['almoxarifado'],
    'estoque_total'         => (int)$p['estoque_total'],
    'cmm'                   => (float)$p['cmm'],
    'cmd'                   => (float)$p['cmd'],
    'saldo_dias'            => (int)$p['saldo_dias'],
    'pedido_sugerido'       => (int)$p['pedido_sugerido'],
    'pedido'                => $p['pedido'],
    'valor_uni'             => $p['valor_uni'],
    'total'                 => $p['total'],
    'transito'              => (int)$p['transito'],
    'transito_pedido'       => $p['pedido'] ?? '',
    'transito_fornecedor'   => $p['fornecedor'] ?? '',
    'or_'                   => $p['or_numero'],
    'fornecedor'            => $p['fornecedor'],
    'foto'                  => $p['foto'],
    'atualizado_em'         => $p['atualizado_em'],
], JSON_UNESCAPED_UNICODE);

$conn->close();
?>
