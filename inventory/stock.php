<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin =$ctx['is_admin'];
$canWrite =$isAdmin || user_can_write($ctx);$by = $ctx['fullname'] ?? $ctx['username'] ?? '';

$pdo->exec("CREATE TABLE IF NOT EXISTS stock (
 id INT AUTO_INCREMENT PRIMARY KEY, product_name VARCHAR(200) NOT NULL, batch_no VARCHAR(50) DEFAULT '',
 expiry DATE DEFAULT NULL, qty DECIMAL(12,2) NOT NULL DEFAULT 0, mrp DECIMAL(12,2) DEFAULT 0,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_prod_batch (product_name, batch_no))");
$pdo->exec("CREATE TABLE IF NOT EXISTS stock_ledger (
 id INT AUTO_INCREMENT PRIMARY KEY, product_name VARCHAR(200) NOT NULL, batch_no VARCHAR(50) DEFAULT '',
 txn_type ENUM('IN','OUT','ADJUST') NOT NULL, qty DECIMAL(12,2) NOT NULL,
 ref_type VARCHAR(40) DEFAULT NULL, ref_no VARCHAR(50) DEFAULT NULL, remarks VARCHAR(255) DEFAULT NULL,
 created_by VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if (isset($_POST['ajax_action'])) {
 header('Content-Type: application/json');
 $a=$_POST['ajax_action'];$r=['status'=>'error','message'=>'Invalid'];
 try {
  if ($a==='save_in' &&$canWrite) {
   $prod=trim($_POST['product_name']??''); $batch=trim($_POST['batch_no']??''); $qty=floatval($_POST['qty']??0);
   if(!$prod||$qty==0){echo json_encode(['status'=>'error','message'=>'Product & qty required']);exit;}$pdo->prepare("INSERT INTO stock (product_name,batch_no,expiry,qty,mrp) VALUES (?,?,?,?,?)
    ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty), expiry=COALESCE(VALUES(expiry),expiry), mrp=IF(VALUES(mrp)>0,VALUES(mrp),mrp)")
    ->execute([$prod,$batch,$_POST['expiry']?:null,$qty,floatval($_POST['mrp']??0)]);$pdo->prepare("INSERT INTO stock_ledger (product_name,batch_no,txn_type,qty,ref_type,ref_no,remarks,created_by) VALUES (?,?,?,?,?,?,?,?)")
    ->execute([$prod,$batch,$qty>=0?'IN':'ADJUST',abs($qty),'Manual',trim($_POST['ref_no']??''),trim($_POST['remarks']??''),$by]);$r=['status'=>'success','message'=>'Stock updated'];
  } elseif ($a==='fetch') {$q=trim($_POST['q']??'');$sql="SELECT * FROM stock WHERE qty<>0"; $p=[];
   if($q!==''){$sql.=" AND (product_name LIKE ? OR batch_no LIKE ?)"; $p[]="\%$q%"; $p[]="\%$q%"; }
   $sql.=" ORDER BY product_name, expiry";
   $st=$pdo->prepare($sql); $st->execute($p);
   $r=['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)];
  } elseif ($a==='ledger') {
   $st=$pdo->prepare("SELECT * FROM stock_ledger WHERE product_name=? ORDER BY id DESC LIMIT 100");
   $st->execute([trim($_POST['product_name']??'')]);
   $r=['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)];
  } elseif ($a==='get_products') {
   try{$rows=$pdo->query("SELECT product_name FROM products ORDER BY product_name")->fetchAll(PDO::FETCH_COLUMN);}
   catch(Exception $e){$rows=$pdo->query("SELECT productname FROM products ORDER BY productname")->fetchAll(PDO::FETCH_COLUMN);}
   $r=['status'=>'success','products'=>$rows];
  }
 } catch(Exception $e){$r['message']=$e->getMessage();}
 echo json_encode($r); exit;
}
$pageTitle="Live Warehouse Stock"; include __DIR__ . '/../includes/header.php';
?>

<div id="msgBox" class="msg-box"></div>

<?php if($canWrite): ?>
<div class="panel-box">
    <div class="panel-title">Stock In / Adjustment Tool</div>
    <div class="form-grid" style="grid-template-columns:1.5fr 1fr 1fr 1fr 1fr 1fr auto">
        <div class="form-group"><label>Product</label><select id="sprod"></select></div>
        <div class="form-group"><label>Batch</label><input id="sbatch" placeholder="Batch No."></div>
        <div class="form-group"><label>Expiry</label><input type="date" id="sexp"></div>
        <div class="form-group"><label>Qty (+ In / - Out)</label><input type="number" id="sqty" step="0.01" value="0"></div>
        <div class="form-group"><label>MRP (₹)</label><input type="number" id="smrp" step="0.01" value="0"></div>
        <div class="form-group"><label>Ref / Remarks</label><input id="srem" placeholder="Optional notes"></div>
        <button class="btn btn-primary" onclick="saveIn()">Save Adjust</button>
    </div>
</div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 3fr 2fr; gap: 15px; align-items: start;">
    
    <div class="panel-box">
        <div class="panel-title">
            <span>Current Live Stock</span>
            <input id="sq" placeholder="Search Product or Batch..." oninput="loadStock()" style="height:22px; font-size:10px; width:180px; padding: 0 6px; border: 1.5px dotted #cbd5e1; border-radius: 2px; outline:none;">
        </div>
        <div style="border: 1.5px dotted #cbd5e1; border-radius: 4px; overflow-y: auto; max-height: 450px;">
            <table class="compact-table">
                <thead><tr><th>Product Name</th><th>Batch No</th><th>Expiry</th><th style="text-align:right;">Stock Qty</th><th style="text-align:right;">MRP</th><th style="text-align:center;">Action</th></tr></thead>
                <tbody id="sbody"></tbody>
            </table>
        </div>
    </div>

    <div class="panel-box">
        <div class="panel-title">Product Ledger: <span id="ledTitle" style="color:var(--sky);">—</span></div>
        <div style="border: 1.5px dotted #cbd5e1; border-radius: 4px; overflow-y: auto; max-height: 450px;">
            <table class="compact-table">
                <thead><tr><th>Date</th><th>Type</th><th style="text-align:right;">Qty</th><th>Ref</th><th>By</th></tr></thead>
                <tbody id="lbody"><tr><td colspan="5" style="text-align:center;color:#94a3b8; font-weight:600; padding:20px;">Select a product to view ledger</td></tr></tbody>
            </table>
        </div>
    </div>
    
</div>

<script>
function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3000)}
function loadProd(){const fd=new FormData();fd.append('ajax_action','get_products');fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
 const s=document.getElementById('sprod'); if(!s)return; s.innerHTML='<option value="">- Select -</option>'+(d.products||[]).map(p=>`<option value="${p}">${p}</option>`).join('')})}
function saveIn(){const fd=new FormData();fd.append('ajax_action','save_in');
 fd.append('product_name',document.getElementById('sprod').value);fd.append('batch_no',document.getElementById('sbatch').value);
 fd.append('expiry',document.getElementById('sexp').value);fd.append('qty',document.getElementById('sqty').value);
 fd.append('mrp',document.getElementById('smrp').value);fd.append('remarks',document.getElementById('srem').value);
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success') { loadStock(); document.getElementById('sqty').value='0'; } })}
function loadStock(){const fd=new FormData();fd.append('ajax_action','fetch');fd.append('q',document.getElementById('sq').value);
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
  document.getElementById('sbody').innerHTML=(d.data||[]).map(s=>`<tr>
   <td><strong style="color:var(--navy-900);">${s.product_name}</strong></td>
   <td style="font-family:monospace; font-weight:bold; color:#475569;">${s.batch_no||'-'}</td>
   <td>${s.expiry||'-'}</td>
   <td class="${s.qty<=0?'low':''}" style="text-align:right; font-weight:bold;">${s.qty}</td>
   <td style="text-align:right; color:#059669; font-weight:bold;">₹${Number(s.mrp).toLocaleString('en-IN')}</td>
   <td style="text-align:center;"><button class="btn btn-secondary btn-sm" onclick="loadLed('${s.product_name.replace(/'/g,"\\'")}')">Ledger</button></td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;color:#94a3b8; font-weight:bold; padding:20px;">Inventory is empty</td></tr>'})}
function loadLed(p){document.getElementById('ledTitle').innerText=p;const fd=new FormData();fd.append('ajax_action','ledger');fd.append('product_name',p);
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
  document.getElementById('lbody').innerHTML=(d.data||[]).map(x=>{
      let tCls = x.txn_type==='IN'?'#059669':(x.txn_type==='OUT'?'#dc2626':'#b45309');
      return `<tr>
        <td style="font-family:monospace;">${x.created_at.substring(0,10)}</td>
        <td><span class="badge" style="color:${tCls}; border-color:${tCls};">${x.txn_type}</span></td>
        <td style="text-align:right; font-weight:bold;">${x.qty}</td>
        <td><span style="font-size:8px;">${x.ref_type||''} ${x.ref_no||''}</span></td>
        <td><span style="font-size:9px; color:var(--navy-900); font-weight:bold;">${x.created_by||'-'}</span></td>
      </tr>`}).join('')||'<tr><td colspan="5" style="text-align:center;color:#94a3b8; font-weight:600; padding:20px;">No transactions logged</td></tr>'})}
window.onload=()=>{loadProd();loadStock()};
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>