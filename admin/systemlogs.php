<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
if (!$ctx['is_admin']) { header('Location: ../dashboard/dashboard.php?access=denied'); exit; }
$pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
 id INT AUTO_INCREMENT PRIMARY KEY, log_level VARCHAR(20) NOT NULL, module VARCHAR(50) NOT NULL,
 message TEXT NOT NULL, user_name VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
if (isset($_POST['ajax_action'])) {
 header('Content-Type: application/json');
 if ($_POST['ajax_action']==='fetch') {
  $lvl=trim($_POST['level']??''); $mod=trim($_POST['module']??'');
  $sql="SELECT * FROM system_logs WHERE 1=1"; $p=[];
  if($lvl){$sql.=" AND log_level=?";$p[]=$lvl;}
  if($mod){$sql.=" AND module LIKE ?";$p[]="%$mod%";}
  $sql.=" ORDER BY id DESC LIMIT 300";
  $st=$pdo->prepare($sql);$st->execute($p);
  echo json_encode(['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
 }
 if ($_POST['ajax_action']==='clear') {
  $pdo->exec("DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
  echo json_encode(['status'=>'success','message'=>'Logs older than 30 days cleared']); exit;
 }
 echo json_encode(['status'=>'error']); exit;
}
$pageTitle="System Logs"; include __DIR__ . '/../includes/header.php';
?>
<div class="panel-box">
<div class="panel-title" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
<span>System Logs</span>
<select id="level" onchange="load()" style="height:26px"><option value="">All levels</option><option>ERROR</option><option>WARNING</option><option>INFO</option></select>
<input id="mod" placeholder="Module" oninput="load()" style="height:26px">
<button class="btn" onclick="load()">Refresh</button>
<button class="btn" style="background:#dc2626" onclick="clr()">Clear 30d+</button>
</div>
<table class="compact-table"><thead><tr><th>Time</th><th>Level</th><th>Module</th><th>User</th><th>Message</th></tr></thead>
<tbody id="body"></tbody></table>
</div>
<script>
function load(){const fd=new FormData();fd.append('ajax_action','fetch');fd.append('level',document.getElementById('level').value);fd.append('module',document.getElementById('mod').value);
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
  document.getElementById('body').innerHTML=(d.data||[]).map(x=>{
   const cls=x.log_level==='ERROR'?'badge-error':(x.log_level==='WARNING'?'badge-warn':'badge-info');
   return`<tr><td style="white-space:nowrap">${x.created_at}</td><td class="${cls}"><strong>${x.log_level}</strong></td><td>${x.module}</td><td>${x.user_name||'-'}</td><td>${x.message}</td></tr>`;
  }).join('')||'<tr><td colspan="5" style="text-align:center;color:#94a3b8">No logs</td></tr>'})}
function clr(){if(!confirm('Clear logs older than 30 days?'))return;const fd=new FormData();fd.append('ajax_action','clear');
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message);load()})}
window.onload=load;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
