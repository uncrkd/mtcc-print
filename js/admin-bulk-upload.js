/**
 * Bulk Order Upload - JavaScript
 * MTCC Print Services
 *
 * Extracted from admin-bulk-upload.php
 * Requires: js/shared/utils.js (escapeHtml)
 * Requires: BULK_UPLOAD_CONFIG global (set by PHP inline script)
 *   - BULK_UPLOAD_CONFIG.eventPrefixes
 */

let parsedOrders = [], warnings = [], duplicateRows = [], deletedRows = new Set(), fileHash = '';
let duplicateFileOverridden = false, duplicateRowsOverridden = false, createdOrders = [];
let pendingFiles = {}, matchedFiles = {};
const eventPrefixes = BULK_UPLOAD_CONFIG.eventPrefixes;
const colMap = {'event prefix':'event_prefix','customer name':'customer_name','company':'company','email':'email','phone':'phone','delivery method':'delivery_method','booth/room':'booth_room','product type':'product_type','material':'material','width (inches)':'width','height (inches)':'height','quantity':'quantity','priority':'priority','status':'status','payment method':'payment_method','payment reference':'payment_reference','base price':'base_price','rush fee':'rush_fee','delivery fee':'delivery_fee','subtotal':'subtotal','tax':'tax','total':'total','submitted':'submitted','due date':'due_date','notes':'notes'};

const uploadZone = document.getElementById('uploadZone'), fileInput = document.getElementById('fileInput');
uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('dragover'); });
uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
uploadZone.addEventListener('drop', e => { e.preventDefault(); uploadZone.classList.remove('dragover'); if(e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]); });
uploadZone.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', e => { if(e.target.files[0]) handleFile(e.target.files[0]); });

async function handleFile(file) {
    if (!file.name.match(/\.(xlsx|xls)$/i)) { alert('Please upload an Excel file'); return; }
    const reader = new FileReader();
    reader.onload = async function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            fileHash = await calculateHash(data);
            const dupCheck = await checkDuplicateFile(fileHash);
            if (dupCheck.duplicate && !duplicateFileOverridden) showDuplicateFileWarning(dupCheck.duplicate);
            const workbook = XLSX.read(data, { type: 'array' });
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            parseSpreadsheet(XLSX.utils.sheet_to_json(firstSheet, { header: 1 }));
        } catch (err) { alert('Error: ' + err.message); }
    };
    reader.readAsArrayBuffer(file);
}

async function calculateHash(data) {
    const hashBuffer = await crypto.subtle.digest('SHA-256', data);
    return Array.from(new Uint8Array(hashBuffer)).map(b => b.toString(16).padStart(2, '0')).join('');
}

async function checkDuplicateFile(hash) {
    const fd = new FormData(); fd.append('action', 'check_spreadsheet_hash'); fd.append('hash', hash);
    return (await fetch('admin-bulk-upload.php', { method: 'POST', body: fd })).json();
}

function showDuplicateFileWarning(dup) {
    document.getElementById('duplicateFileMessage').innerHTML = `<div class="warning-item">This spreadsheet was uploaded on <strong>${new Date(dup.uploaded_at).toLocaleString()}</strong> by <strong>${dup.uploaded_by}</strong> (${dup.order_count} orders).</div>`;
    document.getElementById('duplicateFilePanel').classList.remove('hidden');
}

function overrideDuplicateFile() { duplicateFileOverridden = true; document.getElementById('duplicateFilePanel').classList.add('hidden'); }

async function parseSpreadsheet(data) {
    parsedOrders = []; warnings = []; duplicateRows = []; deletedRows = new Set();
    if (data.length < 3) { alert('Spreadsheet appears empty'); return; }
    const headers = data[0].map(h => String(h).toLowerCase().trim());
    const colIndices = {};
    headers.forEach((h, i) => { if (colMap[h]) colIndices[colMap[h]] = i; });

    for (let i = 2; i < data.length; i++) {
        const row = data[i];
        if (!row || !row.length || !row[0]) continue;
        const order = { _rowNum: i + 1 };
        for (const [key, idx] of Object.entries(colIndices)) order[key] = row[idx] !== undefined ? row[idx] : '';

        const rowNum = i + 1;
        if (!order.event_prefix) warnings.push({ row: rowNum, message: 'Missing event prefix' });
        else if (!eventPrefixes.includes(String(order.event_prefix).toUpperCase())) warnings.push({ row: rowNum, message: `Unknown event "${order.event_prefix}"` });
        if (!order.customer_name) warnings.push({ row: rowNum, message: 'Missing customer name' });
        if (!order.email) warnings.push({ row: rowNum, message: 'Missing email' });
        if (!order.width || !order.height) warnings.push({ row: rowNum, message: 'Missing dimensions' });

        const validStatuses = ['unpaid','paid','preflight','file_issue','printing','ready','dispatched','shipped','delivered','pickedup','unclaimed','missing','cancelled','refunded'];
        if (order.status && !validStatuses.includes(String(order.status).toLowerCase())) {
            warnings.push({ row: rowNum, message: `Invalid status "${order.status}"` });
            order.status = 'unpaid';
        }
        if (String(order.status || 'unpaid').toLowerCase() === 'paid' && !order.payment_method) {
            warnings.push({ row: rowNum, message: 'Paid order missing payment method' });
        }
        parsedOrders.push(order);
    }
    if (!parsedOrders.length) { alert('No valid orders found'); return; }
    await checkDuplicateRows();
    showReviewScreen();
}

async function checkDuplicateRows() {
    const fd = new FormData(); fd.append('action', 'check_duplicate_rows'); fd.append('rows', JSON.stringify(parsedOrders));
    const result = await (await fetch('admin-bulk-upload.php', { method: 'POST', body: fd })).json();
    duplicateRows = result.duplicates || [];
}

function overrideDuplicateRows() { duplicateRowsOverridden = true; document.getElementById('duplicateRowsPanel').classList.add('hidden'); document.getElementById('createOrdersBtn').disabled = false; }

function showReviewScreen() {
    document.getElementById('uploadSection').classList.remove('active');
    document.getElementById('reviewSection').classList.add('active');
    document.getElementById('step1Indicator').classList.remove('active');
    document.getElementById('step1Indicator').classList.add('completed');
    document.getElementById('step2Indicator').classList.add('active');
    renderReviewScreen();
}

function renderReviewScreen() {
    const activeOrders = parsedOrders.filter((_, i) => !deletedRows.has(i));
    const totalValue = activeOrders.reduce((sum, o) => sum + (parseFloat(o.total) || 0), 0);
    const eventGroups = {};
    activeOrders.forEach(order => {
        const prefix = String(order.event_prefix || 'UNKNOWN').toUpperCase();
        if (!eventGroups[prefix]) eventGroups[prefix] = { orders: [], total: 0 };
        eventGroups[prefix].orders.push({ ...order, realIndex: parsedOrders.indexOf(order) });
        eventGroups[prefix].total += parseFloat(order.total) || 0;
    });

    document.getElementById('totalOrders').textContent = activeOrders.length;
    document.getElementById('totalEvents').textContent = Object.keys(eventGroups).length;
    document.getElementById('totalValue').textContent = '$' + totalValue.toFixed(2);
    document.getElementById('totalWarnings').textContent = warnings.length + duplicateRows.length;
    document.getElementById('createCount').textContent = activeOrders.length;
    document.getElementById('deletedCount').textContent = deletedRows.size > 0 ? `${deletedRows.size} row(s) removed` : '';

    const wc = document.getElementById('warningsCard');
    wc.classList.remove('warning','success','error');
    wc.classList.add(duplicateRows.length > 0 ? 'error' : warnings.length > 0 ? 'warning' : 'success');

    const wp = document.getElementById('warningsPanel'), wl = document.getElementById('warningsList');
    if (warnings.length > 0) { wp.classList.remove('hidden'); wl.innerHTML = warnings.slice(0, 10).map(w => `<div class="warning-item"><span class="warning-row">Row ${w.row}</span><span>${w.message}</span></div>`).join('') + (warnings.length > 10 ? `<div class="warning-item">...and ${warnings.length - 10} more</div>` : ''); }
    else wp.classList.add('hidden');

    const dp = document.getElementById('duplicateRowsPanel'), dl = document.getElementById('duplicateRowsList');
    if (duplicateRows.length > 0 && !duplicateRowsOverridden) {
        dp.classList.remove('hidden');
        dl.innerHTML = duplicateRows.map(d => `<div class="warning-item"><span class="warning-row">Row ${parsedOrders[d.row]._rowNum}</span><span>Matches ${d.existing_order}</span></div>`).join('');
        document.getElementById('createOrdersBtn').disabled = true;
    } else { dp.classList.add('hidden'); document.getElementById('createOrdersBtn').disabled = false; }

    const gc = document.getElementById('eventGroups'); gc.innerHTML = '';
    for (const [prefix, group] of Object.entries(eventGroups)) {
        gc.innerHTML += `<div class="event-group expanded" data-prefix="${prefix}">
            <div class="event-group-header" onclick="this.parentElement.classList.toggle('expanded')">
                <div class="event-group-title"><span class="event-prefix">${prefix}</span><span class="event-count">${group.orders.length} order(s)</span></div>
                <div style="display:flex;align-items:center;gap:16px;"><span class="event-total">$${group.total.toFixed(2)}</span><span class="expand-icon">&#9660;</span></div>
            </div>
            <div class="event-group-content"><div style="overflow-x:auto;">
                <table class="orders-table"><thead><tr><th></th><th>Row</th><th>Customer</th><th>Email</th><th>Product</th><th>Size</th><th>Qty</th><th>Status</th><th>Payment</th><th>Total</th></tr></thead>
                <tbody>${group.orders.map(o => renderOrderRow(o)).join('')}</tbody></table>
            </div></div></div>`;
    }
}

function renderOrderRow(order) {
    const idx = order.realIndex, isDeleted = deletedRows.has(idx), isDup = duplicateRows.some(d => d.row === idx);
    const status = String(order.status || 'unpaid').toLowerCase();
    return `<tr class="${isDeleted ? 'deleted' : isDup ? 'duplicate' : ''}" data-index="${idx}">
        <td>${isDeleted ? `<button class="restore-row-btn" onclick="deletedRows.delete(${idx});renderReviewScreen()">Restore</button>` : `<button class="delete-row-btn" onclick="deletedRows.add(${idx});renderReviewScreen()">&#128465;</button>`}</td>
        <td class="row-num">${order._rowNum}</td>
        <td class="editable-cell"><input type="text" value="${escapeHtml(order.customer_name || '')}" onchange="parsedOrders[${idx}].customer_name=this.value;renderReviewScreen()" ${isDeleted ? 'disabled' : ''}></td>
        <td class="editable-cell"><input type="email" value="${escapeHtml(order.email || '')}" onchange="parsedOrders[${idx}].email=this.value" ${isDeleted ? 'disabled' : ''}></td>
        <td class="editable-cell"><select onchange="parsedOrders[${idx}].product_type=this.value" ${isDeleted ? 'disabled' : ''}><option value="poster" ${order.product_type==='poster'?'selected':''}>Poster</option><option value="banner" ${order.product_type==='banner'?'selected':''}>Banner</option><option value="sign" ${order.product_type==='sign'?'selected':''}>Sign</option><option value="fabric" ${order.product_type==='fabric'?'selected':''}>Fabric</option></select></td>
        <td class="editable-cell"><input type="number" value="${order.width||''}" style="width:50px" onchange="parsedOrders[${idx}].width=this.value" ${isDeleted?'disabled':''}> &times; <input type="number" value="${order.height||''}" style="width:50px" onchange="parsedOrders[${idx}].height=this.value" ${isDeleted?'disabled':''}></td>
        <td class="editable-cell"><input type="number" value="${order.quantity||1}" style="width:50px" min="1" onchange="parsedOrders[${idx}].quantity=this.value" ${isDeleted?'disabled':''}></td>
        <td class="editable-cell"><select onchange="parsedOrders[${idx}].status=this.value" ${isDeleted?'disabled':''}><option value="unpaid" ${status==='unpaid'?'selected':''}>Unpaid</option><option value="paid" ${status==='paid'?'selected':''}>Paid</option><option value="printing" ${status==='printing'?'selected':''}>Printing</option><option value="delivered" ${status==='delivered'?'selected':''}>Delivered</option><option value="pickedup" ${status==='pickedup'?'selected':''}>Picked Up</option><option value="cancelled" ${status==='cancelled'?'selected':''}>Cancelled</option></select></td>
        <td class="editable-cell"><select onchange="parsedOrders[${idx}].payment_method=this.value" ${isDeleted?'disabled':''}><option value="">-</option><option value="cash" ${order.payment_method==='cash'?'selected':''}>Cash</option><option value="cheque" ${order.payment_method==='cheque'?'selected':''}>Cheque</option><option value="e-transfer" ${order.payment_method==='e-transfer'?'selected':''}>E-Transfer</option><option value="credit-card" ${order.payment_method==='credit-card'?'selected':''}>Credit Card</option><option value="stripe" ${order.payment_method==='stripe'?'selected':''}>Stripe</option></select></td>
        <td class="editable-cell price"><input type="number" step="0.01" value="${(parseFloat(order.total)||0).toFixed(2)}" onchange="parsedOrders[${idx}].total=this.value;renderReviewScreen()" ${isDeleted?'disabled':''}></td>
    </tr>`;
}

async function createOrders() {
    const btn = document.getElementById('createOrdersBtn');
    btn.disabled = true; btn.innerHTML = '&#9203; Creating...';
    const ordersToCreate = parsedOrders.filter((_, i) => !deletedRows.has(i));
    try {
        const fd = new FormData(); fd.append('action', 'create_orders'); fd.append('orders', JSON.stringify(ordersToCreate)); fd.append('file_hash', fileHash);
        const result = await (await fetch('admin-bulk-upload.php', { method: 'POST', body: fd })).json();
        if (result.success) { createdOrders = result.created; showSuccessScreen(result); }
        else { alert('Error: ' + (result.error || 'Unknown')); btn.disabled = false; btn.innerHTML = '&#9989; Create ' + ordersToCreate.length + ' Orders'; }
    } catch (err) { alert('Error: ' + err.message); btn.disabled = false; btn.innerHTML = '&#9989; Create ' + ordersToCreate.length + ' Orders'; }
}

function showSuccessScreen(result) {
    document.getElementById('reviewSection').classList.remove('active');
    document.getElementById('successSection').classList.add('active');
    document.getElementById('step2Indicator').classList.remove('active');
    document.getElementById('step2Indicator').classList.add('completed');
    document.getElementById('step3Indicator').classList.add('active');

    const created = result.created || [], totalCreated = result.summary?.total_created || created.length;
    document.getElementById('successTitle').textContent = `${totalCreated} Orders Created!`;

    const unpaid = created.filter(c => c.status === 'unpaid'), paid = created.filter(c => c.status === 'paid');
    document.getElementById('unpaidCount').textContent = unpaid.length;
    document.getElementById('paidCount').textContent = paid.length;
    document.getElementById('sendPaymentEmailsBtn').disabled = unpaid.length === 0;
    document.getElementById('sendConfirmationEmailsBtn').disabled = paid.length === 0;

    const byPrefix = {};
    created.forEach(c => { const p = c.reference.split('-')[0]; if (!byPrefix[p]) byPrefix[p] = []; byPrefix[p].push(c.reference); });
    document.getElementById('createdSummary').innerHTML = Object.entries(byPrefix).map(([p, refs]) => `<div class="created-summary-item"><span class="created-prefix">${p}</span><span class="created-range">${refs[0]} \u2192 ${refs[refs.length-1]} (${refs.length})</span></div>`).join('');
}

async function sendPaymentEmails() {
    const btn = document.getElementById('sendPaymentEmailsBtn');
    btn.disabled = true; btn.innerHTML = '&#9203; Sending...';
    const refs = createdOrders.filter(c => c.status === 'unpaid').map(c => c.reference);
    try {
        const fd = new FormData(); fd.append('action', 'send_payment_emails'); fd.append('orders', JSON.stringify(refs));
        const result = await (await fetch('admin-bulk-upload.php', { method: 'POST', body: fd })).json();
        alert(`Sent ${result.sent} email(s)${result.failed > 0 ? ` (${result.failed} failed)` : ''}`);
        btn.innerHTML = '&#9989; Sent!';
    } catch (err) { alert('Error: ' + err.message); btn.disabled = false; btn.innerHTML = '&#128231; Send Payment Links'; }
}

async function sendConfirmationEmails() {
    const btn = document.getElementById('sendConfirmationEmailsBtn');
    btn.disabled = true; btn.innerHTML = '&#9203; Sending...';
    const refs = createdOrders.filter(c => c.status === 'paid').map(c => c.reference);
    try {
        const fd = new FormData(); fd.append('action', 'send_confirmation_emails'); fd.append('orders', JSON.stringify(refs));
        const result = await (await fetch('admin-bulk-upload.php', { method: 'POST', body: fd })).json();
        alert(`Sent ${result.sent} email(s)${result.failed > 0 ? ` (${result.failed} failed)` : ''}`);
        btn.innerHTML = '&#9989; Sent!';
    } catch (err) { alert('Error: ' + err.message); btn.disabled = false; btn.innerHTML = '&#128231; Send Confirmations'; }
}

function showFilesSection() {
    document.getElementById('successSection').classList.remove('active');
    document.getElementById('filesSection').classList.add('active');
    document.getElementById('step3Indicator').classList.remove('active');
    document.getElementById('step3Indicator').classList.add('completed');
    document.getElementById('step4Indicator').classList.add('active');
    initFilesSection();
}

function showSuccessSection() {
    document.getElementById('filesSection').classList.remove('active');
    document.getElementById('successSection').classList.add('active');
    document.getElementById('step4Indicator').classList.remove('active');
    document.getElementById('step3Indicator').classList.remove('completed');
    document.getElementById('step3Indicator').classList.add('active');
}

function initFilesSection() {
    pendingFiles = {}; matchedFiles = {};
    const customerCounts = {};
    createdOrders.forEach(order => {
        const key = order.customer.toLowerCase().trim();
        if (!customerCounts[key]) customerCounts[key] = 0;
        customerCounts[key]++;
        const total = createdOrders.filter(o => o.customer.toLowerCase().trim() === key).length;
        pendingFiles[order.reference] = { reference: order.reference, customer: order.customer, expectedFilename: total > 1 ? `${order.customer}-${order.customerSequence}` : order.customer, matched: false, file: null };
    });
    renderFilesTable();
    setupFilesDropZone();
}

function renderFilesTable() {
    const entries = Object.values(pendingFiles);
    const attached = entries.filter(e => e.matched).length, pending = entries.length - attached, matched = Object.keys(matchedFiles).length;
    document.getElementById('filesPending').textContent = pending;
    document.getElementById('filesAttached').textContent = attached;
    document.getElementById('matchedCount').textContent = matched;
    document.getElementById('attachFilesBtn').disabled = matched === 0;
    document.getElementById('filesTableBody').innerHTML = entries.map(e => `<tr><td><strong>${e.reference}</strong></td><td>${e.customer}</td><td><code>${e.expectedFilename}.pdf</code></td><td>${e.matched ? '<span class="file-status attached">&#9989; Attached</span>' : matchedFiles[e.reference] ? `<span class="file-status matched">&#128194; ${matchedFiles[e.reference].name}</span>` : '<span class="file-status pending">&#9203; Pending</span>'}</td><td>${!e.matched ? `<input type="file" accept=".pdf,.jpg,.jpeg,.png" onchange="matchedFiles['${e.reference}']=this.files[0];renderFilesTable()" style="font-size:0.75rem;max-width:150px;">` : ''}</td></tr>`).join('');
}

function setupFilesDropZone() {
    const zone = document.getElementById('filesUploadZone'), input = document.getElementById('filesInput');
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('dragover'); handleFilesDrop(e.dataTransfer.files); });
    zone.addEventListener('click', () => input.click());
    input.addEventListener('change', e => handleFilesDrop(e.target.files));
}

function handleFilesDrop(files) {
    for (const file of files) {
        const filename = file.name.replace(/\.[^.]+$/, '').toLowerCase().trim();
        for (const [ref, entry] of Object.entries(pendingFiles)) {
            if (entry.matched) continue;
            const expected = entry.expectedFilename.toLowerCase().trim();
            if (filename === expected || filename.includes(expected) || expected.includes(filename)) { matchedFiles[ref] = file; break; }
        }
    }
    renderFilesTable();
}

async function attachMatchedFiles() {
    const btn = document.getElementById('attachFilesBtn');
    btn.disabled = true; btn.innerHTML = '&#9203; Attaching...';
    const attachments = [];
    for (const [ref, file] of Object.entries(matchedFiles)) {
        const data = await new Promise((resolve, reject) => { const r = new FileReader(); r.onload = () => resolve(r.result.split(',')[1]); r.onerror = reject; r.readAsDataURL(file); });
        attachments.push({ reference: ref, filename: file.name, data });
    }
    try {
        const fd = new FormData(); fd.append('action', 'attach_files'); fd.append('attachments', JSON.stringify(attachments));
        const result = await (await fetch('admin-bulk-upload.php', { method: 'POST', body: fd })).json();
        result.details.attached.forEach(ref => { if (pendingFiles[ref]) pendingFiles[ref].matched = true; delete matchedFiles[ref]; });
        alert(`Attached ${result.attached} file(s)${result.failed > 0 ? ` (${result.failed} failed)` : ''}`);
        renderFilesTable(); btn.innerHTML = '&#9989; Attach Files';
    } catch (err) { alert('Error: ' + err.message); btn.disabled = false; btn.innerHTML = '&#9989; Attach Files'; }
}

function resetUpload() {
    parsedOrders = []; warnings = []; duplicateRows = []; deletedRows = new Set(); fileHash = '';
    duplicateFileOverridden = false; duplicateRowsOverridden = false; createdOrders = []; pendingFiles = {}; matchedFiles = {};
    document.getElementById('uploadSection').classList.add('active');
    ['reviewSection','successSection','filesSection'].forEach(id => document.getElementById(id).classList.remove('active'));
    document.getElementById('step1Indicator').classList.add('active');
    document.getElementById('step1Indicator').classList.remove('completed');
    ['step2Indicator','step3Indicator','step4Indicator'].forEach(id => document.getElementById(id).classList.remove('active','completed'));
    document.getElementById('duplicateFilePanel').classList.add('hidden');
    document.getElementById('fileInput').value = '';
}
