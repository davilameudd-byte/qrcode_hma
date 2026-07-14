<?php
// ============================================================
//  ATUALIZAR ESTOQUE HMA - Almoxarifado | PVAX | Trânsito | Farmácia (MED)
//  C:\xampp\htdocs\hma\importar.php
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'Biel231202#');
define('DB_NAME', 'estoque_hospital');

// ── Helpers XLSX ──────────────────────────────────────────
function ziOpen($f){$z=new ZipArchive();return $z->open($f)===true?$z:false;}

function sharedStr($zip){
    $ss=[];$xml=$zip->getFromName('xl/sharedStrings.xml');
    if(!$xml)return $ss;
    $s=new SimpleXMLElement($xml);
    foreach($s->si as $si){$t='';foreach($si->r as $r)$t.=(string)$r->t;if(empty($t))$t=(string)$si->t;$ss[]=$t;}
    return $ss;
}

function getSheetXml($zip,$sheetName=null){
    if($sheetName){
        $wb=$zip->getFromName('xl/workbook.xml');
        if($wb){
            $w=new SimpleXMLElement($wb);
            $ns='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            foreach($w->sheets->sheet as $s){
                if(mb_strtoupper(trim((string)$s['name']))===mb_strtoupper(trim($sheetName))){
                    $rid=(string)$s->attributes($ns)['id'];
                    $rels=$zip->getFromName('xl/_rels/workbook.xml.rels');
                    if($rels){
                        $r=new SimpleXMLElement($rels);
                        foreach($r->Relationship as $rel){
                            if((string)$rel['Id']===$rid){
                                $t=str_replace('../','xl/',(string)$rel['Target']);
                                return $zip->getFromName($t);
                            }
                        }
                    }
                }
            }
        }
    }
    return $zip->getFromName('xl/worksheets/sheet1.xml');
}

function colIdx($ref){preg_match('/([A-Z]+)/',$ref,$m);$l=$m[1];$n=0;for($i=0;$i<strlen($l);$i++)$n=$n*26+(ord($l[$i])-64);return $n-1;}
function cellVal($cell,$ss){$t=(string)($cell['t']??'');$v=(string)($cell->v??'');return $t==='s'?($ss[(int)$v]??''):$v;}

function parseRows($zip,$sheetName=null,$sheetPath=null){
    $ss=sharedStr($zip);
    $xml = $sheetPath ? $zip->getFromName($sheetPath) : getSheetXml($zip,$sheetName);
    if(!$xml)return[];
    $sheet=new SimpleXMLElement($xml);
    $sheet->registerXPathNamespace('ns','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows=[];
    foreach($sheet->xpath('//ns:row') as $row){
        $r=[];foreach($row->c as $c)$r[colIdx((string)$c['r'])]=cellVal($c,$ss);
        $rows[]=$r;
    }
    return $rows;
}

function dbConnect(){
    $c=new mysqli(DB_HOST,DB_USER,DB_PASS,DB_NAME);
    $c->set_charset('utf8mb4');
    return $c;
}

// ── POST: processar arquivo ───────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json; charset=utf-8');

    $tipo = $_POST['tipo'] ?? '';
    if(!isset($_FILES['arquivo'])||$_FILES['arquivo']['error']!==UPLOAD_ERR_OK){
        echo json_encode(['erro'=>'Arquivo não recebido']); exit;
    }
    $tmp  = $_FILES['arquivo']['tmp_name'];
    $nome = $_FILES['arquivo']['name'];
    $ext  = strtolower(pathinfo($nome,PATHINFO_EXTENSION));

    // ── ALMOXARIFADO ──────────────────────────────────────
    if($tipo==='almox'){
        $regs=[];
        if($ext==='csv'){
            $h=fopen($tmp,'r');
            $first=fgets($h);rewind($h);
            $sep=substr_count($first,';')>substr_count($first,',') ? ';' : ',';
            $hdr=array_map(fn($x)=>mb_strtoupper(trim($x),'UTF-8'),fgetcsv($h,0,$sep));
            $cC=$cQ=$cV=$cT=-1;
            foreach($hdr as $i=>$v){
                if(preg_match('/C[ÓO]DIGO|COD/u',$v))$cC=$i;
                if(preg_match('/QUANTIDADE|^QTD/u',$v))$cQ=$i;
                if(preg_match('/VALOR.{0,5}UNI/u',$v))$cV=$i;
                if($v==='TOTAL')$cT=$i;
            }
            if($cC<0)$cC=0;
            while(($row=fgetcsv($h,0,$sep))!==false){
                $cod=trim($row[$cC]??'');
                if(!preg_match('/^\d{2}\.\d{2}\.\d{3}\.\d$/',$cod))continue;
                $r=['cod'=>$cod,'qtd'=>(int)preg_replace('/[^0-9]/','', $row[$cQ]??'0')];
                if($cV>=0)$r['valor_uni']=trim($row[$cV]??'');
                if($cT>=0)$r['total']=trim($row[$cT]??'');
                $regs[]=$r;
            }
            fclose($h);
        } else {
            $zip=ziOpen($tmp);
            if(!$zip){echo json_encode(['erro'=>'Não abriu o XLSX']);exit;}
            $rows=parseRows($zip);$zip->close();
            $cC=$cQ=$cV=$cT=-1;$first=true;
            foreach($rows as $data){
                if($first){
                    foreach($data as $i=>$v){
                        $up=mb_strtoupper(trim($v),'UTF-8');
                        if(preg_match('/C[ÓO]DIGO|COD\s*JDE|^COD$/u',$up))$cC=$i;
                        if(preg_match('/QUANTIDADE|^QTD$|^QTDE$/u',$up))$cQ=$i;
                        if(preg_match('/VALOR.{0,5}UNI/u',$up))$cV=$i;
                        if($up==='TOTAL')$cT=$i;
                    }
                    if($cC<0)$cC=0;
                    $first=false;continue;
                }
                $cod=trim($data[$cC]??'');
                if(!preg_match('/^\d{2}\.\d{2}\.\d{3}\.\d$/',$cod))continue;
                $r=['cod'=>$cod,'qtd'=>(int)($data[$cQ]??0)];
                if($cV>=0&&isset($data[$cV]))$r['valor_uni']='R$ '.number_format((float)$data[$cV],2,',','.');
                if($cT>=0&&isset($data[$cT]))$r['total']='R$ '.number_format((float)$data[$cT],2,',','.');
                $regs[]=$r;
            }
        }
        if(empty($regs)){echo json_encode(['erro'=>'Nenhum produto encontrado. Verifique as colunas Código e Quantidade.']);exit;}
        $db=dbConnect();$ok=$nok=0;
        foreach($regs as $r){
            $st=$db->prepare("UPDATE produtos SET almoxarifado=?,estoque_total=?,valor_uni=COALESCE(?,valor_uni),total=COALESCE(?,total),atualizado_em=CURRENT_TIMESTAMP WHERE cod_jde=?");
            $v=$r['valor_uni']??null;$t=$r['total']??null;
            $st->bind_param('iisss',$r['qtd'],$r['qtd'],$v,$t,$r['cod']);
            $st->execute();
            if($st->affected_rows>0)$ok++;else$nok++;
            $st->close();
        }
        $db->close();
        echo json_encode(['status'=>'OK','mensagem'=>'Estoque Almoxarifado atualizado!','registros_lidos'=>count($regs),'atualizados'=>$ok,'nao_encontrados'=>$nok,'horario'=>date('d/m/Y H:i:s')],JSON_UNESCAPED_UNICODE);

    // ── FARMÁCIA (Posição de Estoque - Medicamentos) ──────
    } elseif($tipo==='farmacia'){
        $regs=[];
        if($ext==='csv'){
            $h=fopen($tmp,'r');
            $first=fgets($h);rewind($h);
            $sep=substr_count($first,';')>substr_count($first,',') ? ';' : ',';
            $hdr=array_map(fn($x)=>mb_strtoupper(trim($x),'UTF-8'),fgetcsv($h,0,$sep));
            $cC=$cQ=$cV=$cT=$cP=-1;
            foreach($hdr as $i=>$v){
                if(preg_match('/C[ÓO]DIGO|COD/u',$v))$cC=$i;
                if(preg_match('/QUANTIDADE|^QTD/u',$v))$cQ=$i;
                if(preg_match('/VALOR.{0,5}UNI/u',$v))$cV=$i;
                if($v==='TOTAL')$cT=$i;
                if(preg_match('/PRODUTO|DESCRI/u',$v))$cP=$i;
            }
            if($cC<0)$cC=0;
            while(($row=fgetcsv($h,0,$sep))!==false){
                $cod=trim($row[$cC]??'');
                if(!preg_match('/^\d{2}\.\d{2}\.\d{3}\.\d$/',$cod))continue;
                $r=['cod'=>$cod,'qtd'=>(int)preg_replace('/[^0-9]/','', $row[$cQ]??'0')];
                if($cV>=0)$r['valor_uni']=trim($row[$cV]??'');
                if($cT>=0)$r['total']=trim($row[$cT]??'');
                if($cP>=0)$r['produto']=trim($row[$cP]??'');
                $regs[]=$r;
            }
            fclose($h);
        } else {
            $zip=ziOpen($tmp);
            if(!$zip){echo json_encode(['erro'=>'Não abriu o XLSX']);exit;}
            $rows=parseRows($zip);$zip->close();
            $cC=$cQ=$cV=$cT=$cP=-1;$first=true;
            foreach($rows as $data){
                if($first){
                    foreach($data as $i=>$v){
                        $up=mb_strtoupper(trim($v),'UTF-8');
                        if(preg_match('/C[ÓO]DIGO|COD\s*JDE|^COD$/u',$up))$cC=$i;
                        if(preg_match('/QUANTIDADE|^QTD$|^QTDE$/u',$up))$cQ=$i;
                        if(preg_match('/VALOR.{0,5}UNI/u',$up))$cV=$i;
                        if($up==='TOTAL')$cT=$i;
                        if(preg_match('/PRODUTO|DESCRI/u',$up))$cP=$i;
                    }
                    if($cC<0)$cC=0;
                    $first=false;continue;
                }
                $cod=trim($data[$cC]??'');
                if(!preg_match('/^\d{2}\.\d{2}\.\d{3}\.\d$/',$cod))continue;
                $r=['cod'=>$cod,'qtd'=>(int)($data[$cQ]??0)];
                if($cV>=0&&isset($data[$cV]))$r['valor_uni']='R$ '.number_format((float)$data[$cV],2,',','.');
                if($cT>=0&&isset($data[$cT]))$r['total']='R$ '.number_format((float)$data[$cT],2,',','.');
                if($cP>=0&&isset($data[$cP]))$r['produto']=trim($data[$cP]);
                $regs[]=$r;
            }
        }
        if(empty($regs)){echo json_encode(['erro'=>'Nenhum medicamento encontrado. Verifique as colunas Código e Quantidade.']);exit;}
        $db=dbConnect();$ok=$nok=0;
        foreach($regs as $r){
            $produto=$r['produto']??'';
            $grupo='MED';
            $v=$r['valor_uni']??null;$t=$r['total']??null;
            $st=$db->prepare("INSERT INTO produtos (cod_jde, produto, grupo, almoxarifado, estoque_total, valor_uni, total, atualizado_em)
                VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                    produto=IF(VALUES(produto)<>'',VALUES(produto),produto),
                    grupo='MED',
                    almoxarifado=VALUES(almoxarifado),
                    estoque_total=VALUES(estoque_total),
                    valor_uni=COALESCE(VALUES(valor_uni),valor_uni),
                    total=COALESCE(VALUES(total),total),
                    atualizado_em=CURRENT_TIMESTAMP");
            $st->bind_param('sssiiss',$r['cod'],$produto,$grupo,$r['qtd'],$r['qtd'],$v,$t);
            $st->execute();
            if($st->affected_rows>0)$ok++;else$nok++;
            $st->close();
        }
        $db->close();
        echo json_encode(['status'=>'OK','mensagem'=>'Estoque da Farmácia atualizado!','registros_lidos'=>count($regs),'atualizados'=>$ok,'nao_encontrados'=>$nok,'horario'=>date('d/m/Y H:i:s')],JSON_UNESCAPED_UNICODE);

    // ── PVAX (ESTOQ149) ───────────────────────────────────
    } elseif($tipo==='pvax'){
        if($ext!=='xlsx'){echo json_encode(['erro'=>'O relatório PVAX deve ser .xlsx']);exit;}
        $zip=ziOpen($tmp);
        if(!$zip){echo json_encode(['erro'=>'Não abriu o XLSX']);exit;}
        // Tentar aba ESTOQ149 pelo nome, depois sheet2, depois sheet1
        $rows=parseRows($zip,'ESTOQ149');
        if(empty($rows)) $rows=parseRows($zip,null,'xl/worksheets/sheet2.xml');
        if(empty($rows)) $rows=parseRows($zip);
        $zip->close();
        $cJ=$cT=$cQ=-1;$first=true;$saldo=[];
        foreach($rows as $data){
            if($first){
                foreach($data as $i=>$v){
                    $up=mb_strtoupper(trim($v),'UTF-8');
                    if(str_contains($up,'JDE'))$cJ=$i;
                    if(str_contains($up,'TIPO DE ESTOQUE'))$cT=$i;
                    if(preg_match('/QTD.{0,5}DISPON/u',$up))$cQ=$i;
                }
                if($cJ<0)$cJ=2;
                if($cT<0)$cT=8;
                if($cQ<0)$cQ=13;
                $first=false;continue;
            }
            $jde=trim($data[$cJ]??'');
            $tp=trim($data[$cT]??'');
            $qt=(int)($data[$cQ]??0);
            if(!preg_match('/^\d{2}\.\d{2}\.\d{3}\.\d$/',$jde))continue;
            if(preg_match('/HOSPITAL\s+DO\s+ANDAR/u', mb_strtoupper($tp,'UTF-8')))$saldo[$jde]=($saldo[$jde]??0)+$qt;
        }
        if(empty($saldo)){
            // Debug: mostrar valores únicos da coluna tipo
            $unicos=[];
            foreach($allRows as $i=>$data){
                if($i===0) continue;
                $tp=trim($data[$cT]??'');
                if($tp) $unicos[$tp]=true;
            }
            echo json_encode(['erro'=>'Nenhum produto do Hospital do Andaraí encontrado.','debug_valores_col_tipo'=>array_keys(array_slice($unicos,0,15,true)),'debug_indices'=>['cJDE'=>$cJ,'cTipo'=>$cT,'cQtd'=>$cQ]],JSON_UNESCAPED_UNICODE); exit;
        }
        $db=dbConnect();$ok=$nok=0;
        $db->query("UPDATE produtos SET estoque_pvax = 0"); // zera tudo
        foreach($saldo as $cod=>$qt){
            $st=$db->prepare("UPDATE produtos SET estoque_pvax=?,atualizado_em=CURRENT_TIMESTAMP WHERE cod_jde=?");
            $st->bind_param('is',$qt,$cod);$st->execute();
            if($st->affected_rows>0)$ok++;else$nok++;
            $st->close();
        }
        $db->close();
        echo json_encode(['status'=>'OK','mensagem'=>'Estoque PVAX atualizado!','registros_lidos'=>count($saldo),'atualizados'=>$ok,'nao_encontrados'=>$nok,'horario'=>date('d/m/Y H:i:s')],JSON_UNESCAPED_UNICODE);

    // ── TRÂNSITO (Previsão de Entradas) ───────────────────
    } elseif($tipo==='transito'){
        $zip=ziOpen($tmp);
        if(!$zip){echo json_encode(['erro'=>'Não abriu o XLSX']);exit;}
        $rows=parseRows($zip,'Relatório');
        if(empty($rows))$rows=parseRows($zip);
        $zip->close();

        // Encontrar linha de cabeçalho (contém 'CÓDIGO')
        $headerRow=-1; $cC=$cS=$cP=$cF=-1;
        foreach($rows as $idx=>$data){
            foreach($data as $v){
                if(preg_match('/C[ÓO]DIGO/u',mb_strtoupper(trim($v),'UTF-8'))){
                    $headerRow=$idx; break;
                }
            }
            if($headerRow>=0) break;
        }
        if($headerRow<0){echo json_encode(['erro'=>'Cabeçalho não encontrado.']);exit;}

        // Mapear colunas pelo cabeçalho
        foreach($rows[$headerRow] as $i=>$v){
            $up=mb_strtoupper(trim($v),'UTF-8');
            if(preg_match('/C[ÓO]DIGO/u',$up))    $cC=$i;  // B = código
            if($up==='SALDO')                       $cS=$i;  // E = saldo/qtd trânsito
            if(str_contains($up,'PEDIDO')&&!str_contains($up,'DATA')&&!str_contains($up,'TIPO')) $cP=$i; // G = nº pedido
            if($up==='FORNECEDOR')                  $cF=$i;  // I = fornecedor
        }
        // Fallback por posição
        if($cC<0) $cC=1;  // col B
        if($cS<0) $cS=4;  // col E
        if($cP<0) $cP=6;  // col G
        if($cF<0) $cF=8;  // col I

        // Coletar todos os registros detalhados
        $registros=[];
        $saldo_total=[];

        for($i=$headerRow+1;$i<count($rows);$i++){
            $data=$rows[$i];
            $cod = trim($data[$cC]??'');
            if(!preg_match('/^\d{2}\.\d{2}\.\d{3}\.\d$/',$cod)) continue;
            $saldo    = (int)($data[$cS]??0);
            $pedido   = trim($data[$cP]??'');
            $fornec   = trim($data[$cF]??'');
            $registros[] = ['cod'=>$cod,'saldo'=>$saldo,'pedido'=>$pedido,'fornecedor'=>$fornec];
            $saldo_total[$cod] = ($saldo_total[$cod]??0) + $saldo;
        }

        if(empty($registros)){echo json_encode(['erro'=>'Nenhum produto encontrado no relatório de trânsito.']);exit;}

        $db=dbConnect();
        $ok=$nok=0;

        // Agrupar por código: somar saldo, concatenar pedidos e fornecedores únicos
        $agrupado=[];
        foreach($registros as $r){
            $cod=$r['cod'];
            if(!isset($agrupado[$cod])) $agrupado[$cod]=['saldo'=>0,'pedidos'=>[],'fornecedores'=>[]];
            $agrupado[$cod]['saldo'] += $r['saldo'];
            if($r['pedido'] && !in_array($r['pedido'],$agrupado[$cod]['pedidos'])) $agrupado[$cod]['pedidos'][]=$r['pedido'];
            if($r['fornecedor'] && !in_array($r['fornecedor'],$agrupado[$cod]['fornecedores'])) $agrupado[$cod]['fornecedores'][]=$r['fornecedor'];
        }

        foreach($agrupado as $cod=>$dados){
            $qt      = $dados['saldo'];
            $pedidos = implode(' | ', $dados['pedidos']);
            $fornec  = implode(' | ', $dados['fornecedores']);
            $st=$db->prepare("UPDATE produtos SET transito=?,atualizado_em=CURRENT_TIMESTAMP WHERE cod_jde=?");
            $st->bind_param('is',$qt,$cod);
            $st->execute();
            if($st->affected_rows>0) $ok++; else $nok++;
            $st->close();
        }

        $db->close();
        echo json_encode(['status'=>'OK','mensagem'=>'Em Trânsito atualizado!','registros_lidos'=>count($registros),'produtos_unicos'=>count($agrupado),'atualizados'=>$ok,'nao_encontrados'=>$nok,'horario'=>date('d/m/Y H:i:s')],JSON_UNESCAPED_UNICODE);

    } else {
        echo json_encode(['erro'=>'Tipo inválido']);
    }
    exit;
}
// ── HTML ──────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Atualizar Estoque — HMA</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:#f0f5ff;min-height:100vh;padding:24px 16px;display:flex;flex-direction:column;align-items:center}
h1{font-size:20px;font-weight:800;color:#0f172a;text-align:center;margin-bottom:4px}
.subtitle{font-size:13px;color:#64748b;text-align:center;margin-bottom:24px}
.logo{width:56px;height:56px;background:linear-gradient(135deg,#0d1f4e,#1e3f8f);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:26px;margin:0 auto 12px}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;width:100%;max-width:1100px}
@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:500px){.grid{grid-template-columns:1fr}}
.box{background:#fff;border-radius:18px;padding:22px;box-shadow:0 4px 20px rgba(14,30,89,.10);border:2px solid #dce8fd;display:flex;flex-direction:column;gap:14px}
.box-header{text-align:center}
.box-icon{font-size:32px;margin-bottom:8px}
.box-title{font-size:15px;font-weight:800;color:#0f172a}
.box-sub{font-size:12px;color:#64748b;margin-top:3px}
.badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;margin-top:6px;text-transform:uppercase;letter-spacing:.4px}
.badge-almox{background:#dce8fd;color:#1e3f8f}
.badge-pvax {background:#EAF3DE;color:#27500A}
.badge-trans{background:#FAEEDA;color:#633806}
.drop{border:2px dashed #a5c0ef;border-radius:10px;padding:18px 12px;text-align:center;cursor:pointer;transition:all .15s;background:#f8fbff;font-size:12px;color:#64748b}
.drop:hover,.drop.over{border-color:#2454b8;background:#eff6ff}
.drop .d-icon{font-size:24px;margin-bottom:6px}
.drop strong{color:#1e3f8f}
.file-badge{margin-top:6px;font-size:11px;font-weight:600;color:#2454b8;background:#dce8fd;padding:2px 8px;border-radius:5px;display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
input[type=file]{display:none}
.tipos{font-size:10px;color:#94a3b8;margin-top:4px}
.btn{width:100%;padding:12px;background:linear-gradient(135deg,#1e3f8f,#2e6be6);color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.btn:disabled{opacity:.45;cursor:not-allowed}
.res{padding:12px;border-radius:10px;display:none;font-size:12px}
.res.ok{background:#EAF3DE;border:1px solid #97C459;color:#27500A}
.res.err{background:#FCEBEB;border:1px solid #F09595;color:#791F1F}
.res h4{font-size:12px;font-weight:800;margin-bottom:6px}
.stat{display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid rgba(0,0,0,.07);font-size:11px}
.stat:last-child{border:none}
.stat .k{opacity:.75}.stat .v{font-weight:700}
</style>
</head>
<body>
<div class="logo">📊</div>
<h1>Atualizar Estoque — Hospital do Andaraí</h1>
<p class="subtitle">Importe cada relatório no seu quadro correspondente</p>

<div class="grid">

  <!-- ALMOXARIFADO -->
  <div class="box">
    <div class="box-header">
      <div class="box-icon">🏥</div>
      <div class="box-title">Estoque Almoxarifado</div>
      <div class="box-sub">Relatório de Posição de Estoque</div>
      <span class="badge badge-almox">Atualiza: almoxarifado</span>
    </div>
    <div class="drop" id="dz-almox" onclick="document.getElementById('fi-almox').click()" ondragover="dov(event,'almox')" ondragleave="dol('almox')" ondrop="ddr(event,'almox')">
      <div class="d-icon">📂</div>
      <p>Arraste ou <strong>clique para selecionar</strong></p>
      <div class="tipos">Aceita: .xlsx · .csv</div>
      <div class="file-badge" id="fn-almox" style="display:none"></div>
    </div>
    <input type="file" id="fi-almox" accept=".xlsx,.xls,.csv" onchange="setF('almox',this)">
    <button class="btn" id="btn-almox" disabled onclick="importar('almox')">📥 Importar Almoxarifado</button>
    <div class="res" id="res-almox"></div>
  </div>

  <!-- PVAX -->
  <div class="box">
    <div class="box-header">
      <div class="box-icon">🏢</div>
      <div class="box-title">Estoque PVAX</div>
      <div class="box-sub">Relatório ESTOQ149</div>
      <span class="badge badge-pvax">Atualiza: estoque_pvax</span>
    </div>
    <div class="drop" id="dz-pvax" onclick="document.getElementById('fi-pvax').click()" ondragover="dov(event,'pvax')" ondragleave="dol('pvax')" ondrop="ddr(event,'pvax')">
      <div class="d-icon">📂</div>
      <p>Arraste ou <strong>clique para selecionar</strong></p>
      <div class="tipos">Aceita: .xlsx (obrigatório)</div>
      <div class="file-badge" id="fn-pvax" style="display:none"></div>
    </div>
    <input type="file" id="fi-pvax" accept=".xlsx" onchange="setF('pvax',this)">
    <button class="btn" id="btn-pvax" disabled onclick="importar('pvax')">📥 Importar PVAX</button>
    <div class="res" id="res-pvax"></div>
  </div>

  <!-- TRÂNSITO -->
  <div class="box">
    <div class="box-header">
      <div class="box-icon">🚚</div>
      <div class="box-title">Em Trânsito</div>
      <div class="box-sub">Previsão de Entradas</div>
      <span class="badge badge-trans">Atualiza: transito</span>
    </div>
    <div class="drop" id="dz-transito" onclick="document.getElementById('fi-transito').click()" ondragover="dov(event,'transito')" ondragleave="dol('transito')" ondrop="ddr(event,'transito')">
      <div class="d-icon">📂</div>
      <p>Arraste ou <strong>clique para selecionar</strong></p>
      <div class="tipos">Aceita: .xlsx</div>
      <div class="file-badge" id="fn-transito" style="display:none"></div>
    </div>
    <input type="file" id="fi-transito" accept=".xlsx" onchange="setF('transito',this)">
    <button class="btn" id="btn-transito" disabled onclick="importar('transito')">📥 Importar Trânsito</button>
    <div class="res" id="res-transito"></div>
  </div>

  <!-- CONSUMO / CMM -->
  <div class="box">
    <div class="box-header">
      <div class="box-icon">📈</div>
      <div class="box-title">Consumo Mensal</div>
      <div class="box-sub">Relatório CMM/CMD</div>
      <span class="badge" style="background:#fce8fb;color:#7e1d7a">Atualiza: CMM · CMD · Saldo</span>
    </div>
    <div class="drop" id="dz-consumo" onclick="document.getElementById('fi-consumo').click()" ondragover="dov(event,'consumo')" ondragleave="dol('consumo')" ondrop="ddr(event,'consumo')">
      <div class="d-icon">📂</div>
      <p>Arraste ou <strong>clique para selecionar</strong></p>
      <div class="tipos">Aceita: .csv (exporte do Excel)</div>
      <div class="file-badge" id="fn-consumo" style="display:none"></div>
    </div>
    <input type="file" id="fi-consumo" accept=".xlsx,.csv,.txt" onchange="setF('consumo',this)">
    <button class="btn" id="btn-consumo" disabled onclick="importar('consumo')" style="background:linear-gradient(135deg,#7e1d7a,#b44db0)">📥 Importar Consumo</button>
    <div class="res" id="res-consumo"></div>
  </div>

  <!-- FARMÁCIA (Medicamentos) -->
  <div class="box">
    <div class="box-header">
      <div class="box-icon">💊</div>
      <div class="box-title">Estoque Farmácia</div>
      <div class="box-sub">Posição de Estoque - Medicamentos</div>
      <span class="badge" style="background:#dbeafe;color:#1e40af">Atualiza: almoxarifado (MED)</span>
    </div>
    <div class="drop" id="dz-farmacia" onclick="document.getElementById('fi-farmacia').click()" ondragover="dov(event,'farmacia')" ondragleave="dol('farmacia')" ondrop="ddr(event,'farmacia')">
      <div class="d-icon">📂</div>
      <p>Arraste ou <strong>clique para selecionar</strong></p>
      <div class="tipos">Aceita: .xlsx · .csv</div>
      <div class="file-badge" id="fn-farmacia" style="display:none"></div>
    </div>
    <input type="file" id="fi-farmacia" accept=".xlsx,.xls,.csv" onchange="setF('farmacia',this)">
    <button class="btn" id="btn-farmacia" disabled onclick="importar('farmacia')" style="background:linear-gradient(135deg,#0f766e,#14b8a6)">📥 Importar Farmácia</button>
    <div class="res" id="res-farmacia"></div>
  </div>

</div>

<div style="display:flex; justify-content:center; margin-top:32px;">
    <a href="exportar_estoque.php"
       style="display:inline-flex; align-items:center; gap:8px;
              background:#1e3a8a; color:#fff; font-weight:bold;
              padding:14px 28px; border-radius:10px; text-decoration:none;
              font-size:15px; box-shadow:0 2px 6px rgba(0,0,0,0.15);">
        📊 Exportar Planilha Consolidada Atualizada
    </a>
</div>

<script>
const files={};

function dov(e,t){e.preventDefault();document.getElementById('dz-'+t).classList.add('over');}
function dol(t){document.getElementById('dz-'+t).classList.remove('over');}
function ddr(e,t){e.preventDefault();dol(t);const f=e.dataTransfer.files[0];if(f)setFObj(t,f);}

function setF(t,input){if(input.files[0])setFObj(t,input.files[0]);}
function setFObj(t,f){
  files[t]=f;
  const fn=document.getElementById('fn-'+t);
  fn.textContent='📄 '+f.name;fn.style.display='inline-block';
  document.getElementById('btn-'+t).disabled=false;
}

async function importar(t){
  const btn=document.getElementById('btn-'+t);
  const res=document.getElementById('res-'+t);
  if(!files[t])return;
  btn.disabled=true;btn.textContent='Importando...';res.style.display='none';
  const fd=new FormData();
  fd.append('arquivo',files[t]);
  fd.append('tipo',t);
  const url = t==='consumo' ? '/hma/atualizar_consumo.php' : '/hma/importar.php';
  try{
    const r=await fetch(url,{method:'POST',body:fd});
    const d=await r.json();
    if(d.status==='OK'){
      res.className='res ok';
      res.innerHTML=`<h4>✅ ${d.mensagem}</h4>
        <div class="stat"><span class="k">Processados</span><span class="v">${d.registros_lidos}</span></div>
        <div class="stat"><span class="k">Atualizados</span><span class="v">${d.atualizados}</span></div>
        <div class="stat"><span class="k">Não encontrados</span><span class="v">${d.nao_encontrados}</span></div>
        <div class="stat"><span class="k">Horário</span><span class="v">${d.horario}</span></div>`;
    } else {
      res.className='res err';
      res.innerHTML=`<h4>❌ Erro</h4><p>${d.erro}</p>`;
    }
  }catch(e){
    res.className='res err';
    res.innerHTML=`<h4>❌ Erro de conexão</h4><p>${e.message}</p>`;
  }
  res.style.display='block';
  btn.disabled=false;btn.textContent='📥 Importar Novamente';
}
</script>
</body>
</html>
