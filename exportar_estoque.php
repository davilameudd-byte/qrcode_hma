<?php
/**
 * exportar_estoque.php
 * Gera a planilha consolidada (.xls) com os dados ATUAIS do banco,
 * no mesmo layout da aba "CONTROLE" da planilha oficial.
 *
 * Coloque este arquivo em: C:\xampp\htdocs\hma\exportar_estoque.php
 * Ajuste $host / $user / $pass / $dbname se forem diferentes do seu api_produto.php
 */

$host   = 'localhost';
$user   = 'root';
$pass   = 'Biel231202#';
$dbname = 'estoque_hospital';

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die('Erro de conexão: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

$sql = "SELECT cod_jde, produto, grupo, tipo_compra, estoque_pvax, almoxarifado,
               estoque_total, cmm, cmd, saldo_dias, pedido_sugerido, pedido,
               valor_uni, total, transito, or_numero, fornecedor, atualizado_em
        FROM produtos
        ORDER BY produto ASC";

$result = $conn->query($sql);
if (!$result) {
    die('Erro na consulta: ' . $conn->error);
}

$dataHora = date('Y-m-d_H-i');
$nomeArquivo = "CONTROLE_DE_ESTOQUE_ATUALIZADO_{$dataHora}.xls";

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
header('Pragma: no-cache');
header('Expires: 0');

function cel($valor) {
    $valor = htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    return "<td>$valor</td>";
}

echo "\xEF\xBB\xBF"; // BOM para acentuação correta no Excel
?>
<table border="1">
<thead>
<tr>
    <th>ITENS DE GRADE</th>
    <th>TIPO DE COMPRA</th>
    <th>GRUPO</th>
    <th>COD JDE</th>
    <th>PRODUTO</th>
    <th>PEDIDO PVAX</th>
    <th>ESTOQUE PVAX</th>
    <th>ALMOXARIFADO</th>
    <th>ESTOQUE TOTAL</th>
    <th>CMM SIEMA</th>
    <th>CMM</th>
    <th>CMD</th>
    <th>SALDO EM DIAS</th>
    <th>PEDIDO SUGERIDO</th>
    <th>PEDIDO</th>
    <th>VALOR SISTEMA</th>
    <th>VALOR UNI2</th>
    <th>TOTAL</th>
    <th>OBS</th>
    <th>QUANTIDADE EM TRANSITO</th>
    <th>OR</th>
    <th>FORNECEDOR</th>
</tr>
</thead>
<tbody>
<?php while ($row = $result->fetch_assoc()): ?>
<tr>
    <?= cel('') /* ITENS DE GRADE - não existe no banco */ ?>
    <?= cel($row['tipo_compra']) ?>
    <?= cel($row['grupo']) ?>
    <?= cel($row['cod_jde']) ?>
    <?= cel($row['produto']) ?>
    <?= cel('') /* PEDIDO PVAX - não existe no banco */ ?>
    <?= cel($row['estoque_pvax']) ?>
    <?= cel($row['almoxarifado']) ?>
    <?= cel($row['estoque_total']) ?>
    <?= cel('') /* CMM SIEMA - não existe no banco */ ?>
    <?= cel($row['cmm']) ?>
    <?= cel($row['cmd']) ?>
    <?= cel($row['saldo_dias']) ?>
    <?= cel($row['pedido_sugerido']) ?>
    <?= cel($row['pedido']) ?>
    <?= cel('') /* VALOR SISTEMA - não existe no banco */ ?>
    <?= cel($row['valor_uni']) ?>
    <?= cel($row['total']) ?>
    <?= cel('') /* OBS - não existe no banco */ ?>
    <?= cel($row['transito']) ?>
    <?= cel($row['or_numero']) ?>
    <?= cel($row['fornecedor']) ?>
</tr>
<?php endwhile; ?>
</tbody>
</table>
<?php
$conn->close();
