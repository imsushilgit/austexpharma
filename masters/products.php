<?php
ob_start();
require_once __DIR__ . '/../includes/core.php';

// AJAX Backend Handler
if (isset($_POST['ajax_action'])) {
    while(ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    // 1. Fetch Products (GUARANTEED DUPLICATE REMOVAL IN PHP)
    if ($_POST['ajax_action'] === 'fetch_products') {$search = trim($_POST['search'] ?? '');$status = trim($_POST['status'] ?? '');$sql = "SELECT * FROM products WHERE 1=1";
        $params = [];
        
        if ($search !== '') {$sql .= " AND (product_name LIKE ? OR productcode LIKE ? OR hsnsac LIKE ?)";
            $params[] = "\%$search%"; $params[] = "\%$search%"; $params[] = "\%$search%";
        }
        if ($status !== '') {$sql .= " AND status = ?";
            $params[] =$status;
        }
        
        $sql .= " ORDER BY product_name ASC";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $allProducts =$stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // PHP Level Deduplication (100% crash-free & strict-mode safe)
            $uniqueProducts = [];$seenNames = [];
            
            foreach ($allProducts as$p) {
                // Remove spaces and make lowercase for accurate matching
                $nameKey = strtolower(trim($p['product_name'])); 
                
                if (!isset($seenNames[$nameKey])) {
                    $seenNames[$nameKey] = true; // Mark as seen
                    $uniqueProducts[] =$p;      // Add to final list
                }
            }
            
            echo json_encode(["status" => "success", "data" => $uniqueProducts]);
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit();
    }
    
    // 2. Save Product (Add / Edit) with Duplicate Check
    if ($_POST['ajax_action'] === 'save_product') {
        try {
            $id = intval($_POST['product_id'] ?? 0);
            $code = trim($_POST['productcode'] ?? '');
            $name = trim($_POST['product_name'] ?? '');
            $type = trim($_POST['producttype'] ?? '');
            $pack = trim($_POST['packing'] ?? '');
            $hsn = trim($_POST['hsnsac'] ?? '');
            $cgst = floatval($_POST['defaultcgst'] ?? 0);
            $sgst = floatval($_POST['defaultsgst'] ?? 0);
            $status = trim($_POST['status'] ?? 'active');
            
            if ($name === '') {
                throw new Exception("Product Name is required.");
            }
            
            // STRICT DUPLICATE CHECK (Case-insensitive)
            $stmtCheck =$pdo->prepare("SELECT COUNT(*) FROM products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?)) AND id != ?");
            $stmtCheck->execute([$name,$id]);
            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception("A product with this exact name already exists.");
            }
            
            if ($id > 0) {
                $stmt =$pdo->prepare("UPDATE products SET productcode=?, product_name=?, producttype=?, packing=?, hsnsac=?, defaultcgst=?, defaultsgst=?, status=? WHERE id=?");
                $stmt->execute([$code, $name,$type, $pack,$hsn, $cgst,$sgst, $status,$id]);
                echo json_encode(["status" => "success", "message" => "Product updated successfully"]);
            } else {
                $stmt =$pdo->prepare("INSERT INTO products (companyid, productcode, product_name, producttype, packing, hsnsac, defaultcgst, defaultsgst, status) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$code,$name, $type,$pack, $hsn,$cgst, $sgst,$status]);
                echo json_encode(["status" => "success", "message" => "New product added successfully"]);
            }
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit();
    }
    
    // 3. Fetch Batches for a specific product
    if ($_POST['ajax_action'] === 'fetch_batches') {
        $pname = trim($_POST['product_name'] ?? '');
        try {
            $stmt =$pdo->prepare("SELECT * FROM product_batches WHERE product_name = ? ORDER BY expiry_date ASC");
            $stmt->execute([$pname]);
            echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit();
    }
    
    // 4. Save Batch
    if ($_POST['ajax_action'] === 'save_batch') {
        try {
            $pname = trim($_POST['batch_product_name'] ?? '');
            $batch = trim($_POST['batch_no'] ?? '');
            $mrp = floatval($_POST['mrp'] ?? 0);
            $expiry = trim($_POST['expiry_date'] ?? '');
            
            if ($pname === '') throw new Exception("Product name is missing.");
            if ($batch === '') throw new Exception("Batch Number is required.");
            
            $stmt =$pdo->prepare("INSERT INTO product_batches (product_name, batch_no, mrp, expiry_date) VALUES (?, ?, ?, ?)");
            $stmt->execute([$pname,$batch, $mrp,$expiry]);
            echo json_encode(["status" => "success", "message" => "Batch added successfully"]);
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit();
    }
}

$pageTitle = "Product Master & Batch Tracker";
include __DIR__ . '/../includes/header.php';
?>


<div class="panel-box">
    <div class="panel-title">Master Product Inventory</div>
    <div class="filter-bar">
        <div class="fg" style="flex: 2;">
            <label>Search Product (Name, Code, HSN)</label>
            <input type="text" id="s_query" placeholder="Type here..." onkeyup="fetchProducts()">
        </div>
        <div class="fg">
            <label>Status Filter</label>
            <select id="s_status" onchange="fetchProducts()">
                <option value="">All</option>
                <option value="active" selected>Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <div style="display: flex; gap: 8px;">
            <button class="btn btn-primary" onclick="openProductModal()">+ New Product</button>
        </div>
    </div>

    <div class="table-container">
        <table class="compact-table">
            <thead>
                <tr>
                    <th style="width: 50px;">Code</th>
                    <th style="text-align: left;">Product Name</th>
                    <th style="text-align: left;">Type</th>
                    <th style="text-align: left;">Packing</th>
                    <th style="text-align: left;">HSN/SAC</th>
                    <th style="text-align: right;">GST Rate</th>
                    <th style="text-align: center;">Status</th>
                    <th style="text-align: center; width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody id="productTbody">
                <tr><td colspan="8" style="text-align: center; padding: 20px; color: #64748b;">Loading Products...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="productModal">
    <div class="modal-box">
        <div class="modal-header">
            <span id="modalTitle">Add New Product</span>
            <span class="modal-close" onclick="closeModal('productModal')">✕</span>
        </div>
        <form id="productForm" onsubmit="saveProduct(event)">
            <input type="hidden" id="p_id" value="0">
            <div class="modal-body">
                <div class="fg">
                    <label>Product Code</label>
                    <input type="text" id="p_code" required>
                </div>
                <div class="fg full">
                    <label>Product Name</label>
                    <input type="text" id="p_name" required>
                </div>
                <div class="fg">
                    <label>Product Type</label>
                    <select id="p_type">
                        <option value="Tablet">Tablet</option>
                        <option value="Capsule">Capsule</option>
                        <option value="Syrup">Syrup</option>
                        <option value="Injection">Injection</option>
                        <option value="Ointment">Ointment</option>
                        <option value="Drops">Drops</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Packing (e.g. 10x10)</label>
                    <input type="text" id="p_pack">
                </div>
                <div class="fg">
                    <label>HSN / SAC</label>
                    <input type="text" id="p_hsn">
                </div>
                <div class="fg">
                    <label>CGST %</label>
                    <input type="number" step="0.01" id="p_cgst" value="6.00">
                </div>
                <div class="fg">
                    <label>SGST %</label>
                    <input type="number" step="0.01" id="p_sgst" value="6.00">
                </div>
                <div class="fg">
                    <label>Status</label>
                    <select id="p_status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" style="background:#f1f5f9; color:#0f172a; border: 1px dotted #cbd5e1;" onclick="closeModal('productModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Product</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="batchModal">
    <div class="modal-box" style="width: 550px;">
        <div class="modal-header">
            <span>Manage Batches: <span id="batchProductNameDisplay" style="color: #93c5fd;"></span></span>
            <span class="modal-close" onclick="closeModal('batchModal')">✕</span>
        </div>
        <div class="modal-body">
            <form id="batchForm" onsubmit="saveBatch(event)" style="display: contents;">
                <input type="hidden" id="b_product_name">
                <div class="fg">
                    <label>Batch Number</label>
                    <input type="text" id="b_no" required>
                </div>
                <div class="fg">
                    <label>MRP (₹)</label>
                    <input type="number" step="0.01" id="b_mrp" required>
                </div>
                <div class="fg">
                    <label>Expiry Date</label>
                    <input type="date" id="b_expiry" required>
                </div>
                <div class="fg" style="justify-content: flex-end;">
                    <button type="submit" class="btn btn-success" style="width: 100%;">Add Batch</button>
                </div>
            </form>
            
            <div class="batch-list">
                <table class="compact-table" style="background: #f8fafc;">
                    <thead>
                        <tr>
                            <th style="text-align: left;">Batch No</th>
                            <th style="text-align:right;">MRP</th>
                            <th style="text-align: left;">Expiry</th>
                            <th style="text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody id="batchTbody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    let productsList = [];

    function fetchProducts() {
        const fd = new FormData();
        fd.append('ajax_action', 'fetch_products');
        fd.append('search', document.getElementById('s_query').value);
        fd.append('status', document.getElementById('s_status').value);
        
        fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
            if(res.status === 'success') {
                productsList = res.data;
                let html = '';
                if(res.data.length === 0) {
                    html = '<tr><td colspan="8" style="text-align:center; color: #64748b; padding: 20px;">No products found</td></tr>';
                } else {
                    res.data.forEach(p => {
                        let totalGst = parseFloat(p.defaultcgst) + parseFloat(p.defaultsgst);
                        let badge = p.status === 'active' ? 'status-active' : 'status-inactive';
                        let pname = p.product_name.replace(/'/g, "\\'");
                        
                        let dispCode = p.productcode ? p.productcode : '-';
                        let dispType = p.producttype ? p.producttype : '-';
                        let dispPack = p.packing ? p.packing : '-';
                        let dispHsn = p.hsnsac ? p.hsnsac : '-';
                        
                        html += `<tr>
                            <td style="font-family: monospace; font-weight:bold; color:#1e2a78;">${dispCode}</td>
                            <td style="color: #0f172a; font-weight:bold;">${p.product_name}</td>
                            <td>${dispType}</td>
                            <td>${dispPack}</td>
                            <td style="font-family: monospace; color:#64748b;">${dispHsn}</td>
                            <td style="text-align:right; font-weight:bold;">${totalGst}%</td>
                            <td style="text-align:center;"><span class="${badge}">${p.status.toUpperCase()}</span></td>
                            <td style="text-align: center;">
                                <button class="btn btn-warning action-btn" onclick="editProduct(${p.id})">Edit</button>
                                <button class="btn btn-primary action-btn" onclick="openBatchModal('${pname}')">Batches</button>
                            </td>
                        </tr>`;
                    });
                }
                document.getElementById('productTbody').innerHTML = html;
            } else {
                if(typeof AppAlert !== 'undefined') { AppAlert.error("Failed to load products"); }
            }
        }).catch(() => {
            if(typeof AppAlert !== 'undefined') { AppAlert.error("Network connection error"); }
        });
    }

    function openProductModal() {
        document.getElementById('productForm').reset();
        document.getElementById('p_id').value = '0';
        document.getElementById('modalTitle').innerText = 'Add New Product';
        document.getElementById('productModal').style.display = 'flex';
    }

    function editProduct(id) {
        let p = productsList.find(x => x.id == id);
        if(!p) { return; }
        
        document.getElementById('p_id').value = p.id;
        document.getElementById('p_code').value = p.productcode;
        document.getElementById('p_name').value = p.product_name;
        document.getElementById('p_type').value = p.producttype;
        document.getElementById('p_pack').value = p.packing;
        document.getElementById('p_hsn').value = p.hsnsac;
        document.getElementById('p_cgst').value = p.defaultcgst;
        document.getElementById('p_sgst').value = p.defaultsgst;
        document.getElementById('p_status').value = p.status;
        
        document.getElementById('modalTitle').innerText = 'Edit Product';
        document.getElementById('productModal').style.display = 'flex';
    }

    function saveProduct(e) {
        e.preventDefault();
        const fd = new FormData();
        fd.append('ajax_action', 'save_product');
        fd.append('product_id', document.getElementById('p_id').value);
        fd.append('productcode', document.getElementById('p_code').value);
        fd.append('product_name', document.getElementById('p_name').value);
        fd.append('producttype', document.getElementById('p_type').value);
        fd.append('packing', document.getElementById('p_pack').value);
        fd.append('hsnsac', document.getElementById('p_hsn').value);
        fd.append('defaultcgst', document.getElementById('p_cgst').value);
        fd.append('defaultsgst', document.getElementById('p_sgst').value);
        fd.append('status', document.getElementById('p_status').value);

        fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
            if(res.status === 'success') {
                if(typeof AppAlert !== 'undefined') { AppAlert.success(res.message); }
                closeModal('productModal');
                fetchProducts();
            } else {
                if(typeof AppAlert !== 'undefined') { AppAlert.error(res.message); }
            }
        });
    }

    function openBatchModal(productName) {
        document.getElementById('batchForm').reset();
        document.getElementById('b_product_name').value = productName;
        document.getElementById('batchProductNameDisplay').innerText = productName;
        document.getElementById('batchModal').style.display = 'flex';
        fetchBatches(productName);
    }

    function fetchBatches(productName) {
        const fd = new FormData();
        fd.append('ajax_action', 'fetch_batches');
        fd.append('product_name', productName);
        
        document.getElementById('batchTbody').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#64748b;">Loading...</td></tr>';
        
        fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
            if(res.status === 'success') {
                let html = '';
                if(res.data.length === 0) {
                    html = '<tr><td colspan="4" style="text-align:center; color: #64748b;">No batches found</td></tr>';
                } else {
                    let today = new Date();
                    res.data.forEach(b => {
                        let expDate = new Date(b.expiry_date);
                        let expBadge = '';
                        if(expDate < today) {
                            expBadge = '<span class="status-inactive">EXPIRED</span>';
                        } else {
                            expBadge = '<span class="status-active">VALID</span>';
                        }
                        
                        html += `<tr>
                            <td style="font-weight:bold; font-family:monospace; color:#1e2a78;">${b.batch_no}</td>
                            <td style="text-align:right; color:#059669; font-weight:bold;">₹${b.mrp}</td>
                            <td>${b.expiry_date}</td>
                            <td style="text-align:center;">${expBadge}</td>
                        </tr>`;
                    });
                }
                document.getElementById('batchTbody').innerHTML = html;
            }
        });
    }

    function saveBatch(e) {
        e.preventDefault();
        const fd = new FormData();
        fd.append('ajax_action', 'save_batch');
        fd.append('batch_product_name', document.getElementById('b_product_name').value);
        fd.append('batch_no', document.getElementById('b_no').value);
        fd.append('mrp', document.getElementById('b_mrp').value);
        fd.append('expiry_date', document.getElementById('b_expiry').value);

        fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
            if(res.status === 'success') {
                if(typeof AppAlert !== 'undefined') { AppAlert.success(res.message); }
                document.getElementById('batchForm').reset();
                fetchBatches(document.getElementById('b_product_name').value);
            } else {
                if(typeof AppAlert !== 'undefined') { AppAlert.error(res.message); }
            }
        });
    }

    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    document.addEventListener("DOMContentLoaded", fetchProducts);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>