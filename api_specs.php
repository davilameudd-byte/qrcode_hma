<?php
require_once __DIR__ . '/config_secrets.php';
// ============================================================
//  API ESPECIFICAÇÕES - Almoxarifado HMA
//  C:\xampp\htdocs\hma\api_specs.php
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: ngrok-skip-browser-warning, Accept, Content-Type');
header('ngrok-skip-browser-warning: true');Z

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$cod     = trim($_GET['cod']     ?? '');
$produto = trim($_GET['produto'] ?? '');
$grupo   = trim($_GET['grupo']   ?? '');

if (!$produto) { echo json_encode(['erro' => 'Produto não informado']); exit; }

// Cache em arquivo para não chamar a API toda vez
$cacheDir  = __DIR__ . '/specs_cache/';
$cacheFile = $cacheDir . preg_replace('/[^a-z0-9]/', '_', strtolower($cod)) . '.json';

if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);

// Retornar cache se existir e tiver menos de 30 dias
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
    echo file_get_contents($cacheFile);
    exit;
}

// Chamar API Anthropic
$prompt = "Você é especialista em materiais hospitalares. Para o produto abaixo, gere especificações técnicas detalhadas.\nResponda APENAS JSON válido, sem markdown.\n\nProduto: \"$produto\"\nGrupo: \"$grupo\"\n\n{\"categoria\":\"categoria clínica curta\",\"material\":\"material de composição\",\"dimensoes\":\"dimensões ou capacidade\",\"uso_clinico\":\"indicação de uso em 1 frase\",\"apresentacao\":\"como vem embalado\",\"unidade_medida\":\"Rolo/Unidade/Pacote etc\",\"validade\":\"validade em meses (só número)\",\"armazenamento\":\"condição de armazenamento\",\"norma_tecnica\":\"norma ABNT ou RDC se houver\",\"cuidados\":\"1 cuidado crítico no manuseio\"}";

$payload = json_encode([
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 600,
    'messages'   => [['role' => 'user', 'content' => $prompt]]
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,,
    ],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(['erro' => 'API indisponível', 'http' => $httpCode]);
    exit;
}

$data = json_decode($response, true);
$text = $data['content'][0]['text'] ?? '';
$text = preg_replace('/```json|```/', '', $text);

$specs = json_decode(trim($text), true);
if (!$specs) {
    echo json_encode(['erro' => 'Resposta inválida da API']);
    exit;
}

// Salvar cache
file_put_contents($cacheFile, json_encode($specs, JSON_UNESCAPED_UNICODE));

echo json_encode($specs, JSON_UNESCAPED_UNICODE);
?>
