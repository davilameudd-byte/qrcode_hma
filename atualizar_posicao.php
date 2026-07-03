<?php
// ============================================================
//  ATUALIZAR POSIÇÃO DE ESTOQUE - Almoxarifado HMA
//  Salvar em: C:\xampp\htdocs\hma\atualizar_posicao.php
//  Acesse:    http://localhost/hma/atualizar_posicao.php
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'Biel231202#');
define('DB_NAME', 'estoque_hospital');

header('Content-Type: application/json; charset=utf-8');

// ── Receber arquivo enviado ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['erro' => 'Envie o arquivo via POST']);
    exit;
}

if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['erro' => 'Nenhum arquivo recebido ou erro no upload']);
    exit;
}

$tmpFile   = $_FILES['arquivo']['tmp_name'];
$nomeArq   = $_FILES['arquivo']['name'];
$extensao  = strtolower(pathinfo($nomeArq, PATHINFO_EXTENSION));

// Aceita xlsx ou csv
if (!in_array($extensao, ['xlsx', 'csv', 'xls'])) {
    echo json_encode(['erro' => 'Formato inválido. Envie .xlsx ou .csv']);
    exit;
}

// ── Parsear o arquivo com Python (mais robusto para xlsx) ──
$pythonScript = tempnam(sys_get_temp_dir(), 'hma_') . '.py';
$outputFile   = tempnam(sys_get_temp_dir(), 'hma_') . '.json';

$script = <<<PYTHON
import pandas as pd, json, sys

try:
    df = pd.read_excel('$tmpFile') if '$extensao' in ['xlsx','xls'] else pd.read_csv('$tmpFile')
    df.columns = [c.strip().upper() for c in df.columns]
    
    # Mapear colunas flexivelmente
    col_map = {}
    for c in df.columns:
        cu = c.upper()
        if 'CÓDIGO' in cu or 'CODIGO' in cu or cu == 'COD':
            col_map['cod'] = c
        elif 'QUANTIDADE' in cu or cu == 'QTD' or cu == 'QTDE':
            col_map['qtd'] = c
        elif 'VALOR' in cu and 'UNI' in cu:
            col_map['valor_uni'] = c
        elif 'TOTAL' in cu:
            col_map['total'] = c
        elif 'PRODUTO' in cu or 'DESCRI' in cu:
            col_map['produto'] = c

    if 'cod' not in col_map or 'qtd' not in col_map:
        print(json.dumps({'erro': f'Colunas não encontradas. Colunas detectadas: {list(df.columns)}'}))
        sys.exit()

    registros = []
    for _, row in df.iterrows():
        cod = str(row[col_map['cod']]).strip()
        import re
        if not re.match(r'\d{2}\.\d{2}\.\d{3}\.\d', cod):
            continue
        reg = {
            'cod': cod,
            'qtd': int(float(str(row[col_map['qtd']]).replace(',','.') or 0)),
        }
        if 'valor_uni' in col_map:
            reg['valor_uni'] = str(row[col_map['valor_uni']]).strip()
        if 'total' in col_map:
            reg['total'] = str(row[col_map['total']]).strip()
        if 'produto' in col_map:
            reg['produto'] = str(row[col_map['produto']]).strip()
        registros.append(reg)

    print(json.dumps({'registros': registros, 'total': len(registros)}))
except Exception as e:
    print(json.dumps({'erro': str(e)}))
PYTHON;

file_put_contents($pythonScript, $script);
$saida = shell_exec("python3 $pythonScript 2>&1");
unlink($pythonScript);

$dados = json_decode($saida, true);
if (!$dados || isset($dados['erro'])) {
    echo json_encode(['erro' => $dados['erro'] ?? 'Erro ao processar arquivo', 'detalhe' => $saida]);
    exit;
}

// ── Atualizar MySQL ────────────────────────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    echo json_encode(['erro' => 'Conexão falhou: ' . $conn->connect_error]);
    exit;
}

$atualizados = 0;
$nao_encontrados = 0;

foreach ($dados['registros'] as $r) {
    // Atualiza almoxarifado e estoque_total com a quantidade do relatório
    // e valor unitário se disponível
    $campos = "almoxarifado = ?, estoque_total = ?";
    $params = [$r['qtd'], $r['qtd']];
    $tipos  = "ii";

    if (!empty($r['valor_uni'])) {
        $campos .= ", valor_uni = ?";
        $params[] = $r['valor_uni'];
        $tipos .= "s";
    }
    if (!empty($r['total'])) {
        $campos .= ", total = ?";
        $params[] = $r['total'];
        $tipos .= "s";
    }

    $campos .= ", atualizado_em = CURRENT_TIMESTAMP";
    $params[] = $r['cod'];
    $tipos .= "s";

    $stmt = $conn->prepare("UPDATE produtos SET $campos WHERE cod_jde = ?");
    if (!$stmt) { $nao_encontrados++; continue; }

    $stmt->bind_param($tipos, ...$params);
    $stmt->execute();

    if ($stmt->affected_rows > 0) $atualizados++;
    else $nao_encontrados++;
    $stmt->close();
}

$conn->close();

echo json_encode([
    'status'          => 'OK',
    'mensagem'        => 'Posição de estoque atualizada!',
    'registros_lidos' => $dados['total'],
    'atualizados'     => $atualizados,
    'nao_encontrados' => $nao_encontrados,
    'horario'         => date('d/m/Y H:i:s')
], JSON_UNESCAPED_UNICODE);
?>
