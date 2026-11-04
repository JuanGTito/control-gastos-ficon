import fs from 'node:fs/promises';
import path from 'node:path';
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runtime = path.join(root, '.runtime');
const results = path.join(root, 'test-results');
await fs.mkdir(results, { recursive: true });
const credentials = JSON.parse(await fs.readFile(path.join(runtime, 'mysql-credentials.json'), 'utf8'));
const password = `Prueba${randomBytes(16).toString('hex')}9`;
const testEnv = { ...process.env, APP_ENV: 'local', APP_URL: 'http://localhost:8000', PHPRC: path.join(runtime, 'php.ini'), DB_CONNECTION: 'mysql', DB_HOST: '127.0.0.1', DB_PORT: String(credentials.port), DB_DATABASE: 'control_gastos_e2e', DB_USERNAME: 'gastos_app', DB_PASSWORD: credentials.app, SESSION_COOKIE: 'gastos_ficon_e2e', PRIVATE_STORAGE_PATH: path.join(runtime, 'e2e-receipts') };
// Detener el servidor de uso normal antes de ejecutar; todas las pruebas usan solo localhost:8000.
execFileSync('php', ['scripts/browser-fixtures.php'], { cwd: root, env: testEnv, input: JSON.stringify({ password }), windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
const server = spawn('php', ['-S', 'localhost:8000', path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env: testEnv, windowsHide: true, stdio: 'ignore' });
let socket;
const report = [];
const errors = [];
let cashPath;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
try {
    for (let i = 0; i < 40; i++) {
        try { if ((await fetch('http://localhost:8000/login', { signal: AbortSignal.timeout(2000) })).ok) break; } catch {}
        await delay(150);
    }
    const tabs = await (await fetch('http://127.0.0.1:9225/json/list')).json();
    const target = tabs.find(tab => tab.type === 'page');
    assert(target, 'Inicia Chrome de pruebas con --remote-debugging-port=9225.');
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
    let sequence = 0;
    const pending = new Map();
    socket.addEventListener('message', event => {
        const message = JSON.parse(event.data);
        if (message.id && pending.has(message.id)) {
            const entry = pending.get(message.id); pending.delete(message.id); clearTimeout(entry.timer);
            message.error ? entry.reject(new Error(JSON.stringify(message.error))) : entry.resolve(message.result);
        }
        if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    });
    function send(method, params = {}) {
        return new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Tiempo agotado: ${method}`)); }, 15000);
            pending.set(id, { resolve, reject, timer }); socket.send(JSON.stringify({ id, method, params }));
        });
    }
    async function evaluate(expression) {
        const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    }
    async function waitFor(expression) {
        const deadline = Date.now() + 20000;
        while (Date.now() < deadline) {
            try { if (await evaluate(expression)) return; } catch {}
            await delay(80);
        }
        throw new Error(`No se cumplió: ${expression}`);
    }
    async function navigate(url) { await send('Page.navigate', { url: `http://localhost:8000${url}` }); await waitFor(`location.pathname === ${JSON.stringify(url.split('?')[0])} && document.readyState === 'complete'`); }
    async function fill(selector, value) { await evaluate(`(() => { const element = document.querySelector(${JSON.stringify(selector)}); if (!element) throw Error('Campo inexistente'); element.value = ${JSON.stringify(value)}; element.dispatchEvent(new Event('input', {bubbles:true})); })()`); }
    async function click(selector) { await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`); }
    async function screenshot(name) { await delay(100); const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); await fs.writeFile(path.join(results, name), Buffer.from(shot.data, 'base64')); }
    async function viewport(width, height, mobile = false) { await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile }); }
    async function noOverflow(label) { const size = await evaluate('({ viewport: innerWidth, body: document.documentElement.scrollWidth })'); assert(size.body <= size.viewport, `${label}: ${JSON.stringify(size)}`); report.push(`${label}: sin desbordamiento horizontal`); }
    async function login(username) { await navigate('/login'); await fill('[name=login]', username); await fill('[name=password]', password); await evaluate('document.querySelector("form").requestSubmit()'); await waitFor('location.pathname === "/gastos" && !!document.querySelector("[data-new-expense]")'); }
    await send('Page.enable'); await send('Runtime.enable');
    await send('Emulation.setEmulatedMedia', { features: [{name:'prefers-color-scheme',value:'light'}] });
    await viewport(1440, 1000);
    await navigate('/login');
    await evaluate('localStorage.removeItem("gastos-ficon-theme")');
    await navigate('/login');
    assert.equal(await evaluate('document.documentElement.lang'), 'es');
    assert(await evaluate('document.title.includes("Gastos Ficon") && document.querySelector(".ficon-brand img").naturalWidth > 0'));
    await click('[data-theme-toggle]');
    assert.equal(await evaluate('document.documentElement.dataset.theme'), 'dark');
    await screenshot('ficon-login-dark.png');
    await navigate('/login');
    assert.equal(await evaluate('document.documentElement.dataset.theme'), 'dark');
    await click('[data-theme-toggle]');
    assert.equal(await evaluate('document.documentElement.dataset.theme'), 'light');
    report.push('Logo Ficon y temas claro/oscuro con preferencia persistida');
    await screenshot('login-desktop.png');
    await noOverflow('Login escritorio');
    await login('prueba_superadmin');
    assert(await evaluate('document.body.innerText.includes("Gasto privado de admin") && document.body.innerText.includes("Gasto privado de user")'));
    report.push('Superadministrador: consulta de todos los gastos');
    await navigate('/configuracion');
    assert(!(await evaluate('document.body.innerText.includes("Mi espacio de trabajo") || document.body.innerText.includes("Espacio privado") || document.body.innerText.includes("Un gasto a la vez.")')));
    assert(await evaluate('document.querySelector(".catalog-table tbody tr").getBoundingClientRect().height <= 56'));
    report.push('Textos eliminados y filas compactas en catálogos');
    await click('[data-new-catalog]');
    await waitFor('document.querySelector("#catalog-dialog").open');
    await fill('#catalog-form [name=name]', 'Gastos de campo');
    await evaluate('document.querySelector("#catalog-form").requestSubmit()');
    await waitFor('document.body.innerText.includes("Catálogo guardado.")');
    assert(await evaluate('document.body.innerText.includes("Gastos de campo")'));
    await screenshot('catalogos-desktop.png');
    report.push('Categoría creada por modal y disponible sin cambiar código');
    await navigate('/usuarios');
    assert(!(await evaluate('document.querySelector(".expense-table tbody").innerText.includes("Prueba superadmin")')));
    report.push('Superadministrador excluido de la lista operativa de usuarios');
    await click('[data-new-user]');
    await waitFor('document.querySelector("#user-dialog").open');
    await fill('#user-form [name=name]', 'María Prueba');
    await fill('#user-form [name=username]', 'maria_prueba');
    await fill('#user-form [name=email]', 'maria@browser.test');
    await fill('#user-form [name=password]', password);
    await fill('#user-form [name=password_confirmation]', password);
    await evaluate('document.querySelector("#user-form").requestSubmit()');
    await waitFor('document.body.innerText.includes("Usuario guardado correctamente.")');
    report.push('Creación de usuario mediante modal');
    await navigate('/caja-chica');
    await click('[data-modal-open="cash-dialog"]');
    await waitFor('document.querySelector("#cash-dialog").open');
    const ownerId = await evaluate('[...document.querySelector("#cash-dialog [name=user_id]").options].find(option => option.textContent.includes("María Prueba")).value');
    await fill('#cash-dialog [name=user_id]', ownerId);
    await fill('#cash-dialog [name=received_amount]', '100.00');
    await fill('#cash-dialog [name=purpose]', 'Compras de oficina verificadas en navegador');
    await fill('#cash-dialog [name=dni]', '00123456');
    await fill('#cash-dialog [name=area]', 'Operaciones');
    await evaluate('document.querySelector("#cash-dialog form").requestSubmit()');
    await waitFor('/^\\/caja-chica\\/\\d+$/.test(location.pathname) && document.body.innerText.includes("Entrega de caja chica guardada.")');
    cashPath = await evaluate('location.pathname');
    report.push('Entrega de caja chica de 100.00 a una persona real de la prueba');
    await click('form[action$="/logout"] button');
    await waitFor('location.pathname === "/login"');
    await login('maria_prueba');
    assert(await evaluate('document.body.innerText.includes("Tus cuentas claras empiezan aquí")'));
    assert(!(await evaluate('document.body.innerText.includes("Gasto privado de admin")')));
    await navigate(`/gastos?petty_cash_account_id=${cashPath.split('/').pop()}`);
    await click('[data-new-expense]');
    await waitFor('document.querySelector("#expense-dialog").open');
    await fill('#expense-form [name=amount]', '24.90');
    await fill('#expense-form [name=description]', 'Almuerzo de prueba en navegador');
    await fill('#expense-form [name=category]', 'Alimentación');
    await fill('#expense-form [name=provider]', 'Restaurante de prueba');
    await fill('#expense-form [name=tax_id]', '20123456789');
    const receiptTypeId = await evaluate('[...document.querySelector("#expense-form [name=receipt_type_id]").options].find(option => option.textContent.trim() === "Boleta").value');
    await fill('#expense-form [name=receipt_type_id]', receiptTypeId);
    await fill('#expense-form [name=series]', 'B001');
    await fill('#expense-form [name=document_number]', '0000123');
    await screenshot('modal-desktop.png');
    const upload = await send('DOM.getDocument');
    const input = await send('DOM.querySelector', { nodeId: upload.root.nodeId, selector: '#expense-form [name=receipt]' });
    const png = path.join(results, 'receipt-fixture.png');
    await fs.writeFile(png, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aou0AAAAASUVORK5CYII=', 'base64'));
    await send('DOM.setFileInputFiles', { nodeId: input.nodeId, files: [png] });
    await evaluate('document.querySelector("#expense-form").requestSubmit()');
    await waitFor('document.body.innerText.includes("Gasto registrado correctamente.")');
    assert(await evaluate('document.body.innerText.includes("24.90")'));
    report.push('Registro real con comprobante PNG privado');
    await click('[data-edit-expense]');
    await waitFor('document.querySelector("#expense-dialog").open');
    assert.equal(await evaluate('document.querySelector("#expense-form [name=amount]").value'), '24.90');
    await fill('#expense-form [name=amount]', '31.25');
    await evaluate('document.querySelector("#expense-form").requestSubmit()');
    await waitFor('document.body.innerText.includes("Gasto actualizado.")');
    await screenshot('gastos-desktop.png');
    await noOverflow('Panel escritorio');
    report.push('Edición real y conservación del comprobante');
    await click('a[href$="/historial"]');
    await waitFor('location.pathname.endsWith("/historial") && document.body.innerText.includes("31.25")');
    assert(await evaluate('document.body.innerText.includes("24.90") && document.body.innerText.includes("María Prueba")'));
    await screenshot('historial-desktop.png');
    report.push('Auditoría: actor, importe anterior y nuevo visibles');
    await navigate('/gastos');
    for (const format of ['xlsx', 'pdf']) {
        const download = await evaluate(`(async () => { const response = await fetch('/reportes/${format}?category=Alimentaci%C3%B3n'); const bytes = new Uint8Array(await response.arrayBuffer()); return {status:response.status, data:btoa(Array.from(bytes, byte => String.fromCharCode(byte)).join(''))}; })()`);
        assert.equal(download.status, 200);
        const bytes = Buffer.from(download.data, 'base64');
        assert(bytes.length > 500);
        assert(format === 'pdf' ? bytes.subarray(0, 5).toString() === '%PDF-' : bytes.subarray(0, 2).toString() === 'PK');
        await fs.writeFile(path.join(results, `reporte-navegador.${format}`), bytes);
        report.push(`Exportación ${format.toUpperCase()}: descarga válida desde sesión real`);
    }
    assert.equal(await evaluate('(async () => (await fetch(document.querySelector("a[href$=\\"/comprobante\\"]").href)).status)()'), 200);
    const cookies = await send('Network.getCookies', { urls: ['http://localhost:8000'] });
    const forged = await fetch('http://localhost:8000/gastos', { method: 'POST', redirect: 'manual', headers: { Cookie: cookies.cookies.map(cookie => `${cookie.name}=${cookie.value}`).join('; '), Origin: 'https://otro-origen.test', 'Sec-Fetch-Site': 'cross-site', 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'amount=99' });
    assert.equal(forged.status, 419);
    assert.equal(await evaluate('(async () => (await fetch("/usuarios")).status)()'), 403);
    report.push('CSRF desde otro origen sin token rechazado (419) y gestión de usuarios denegada (403)');
    await fill('.filter-form [name=q]', 'No existe este gasto');
    await evaluate('document.querySelector(".filter-form").requestSubmit()');
    await waitFor('document.body.innerText.includes("No encontramos gastos con estos filtros")');
    report.push('Filtros reales y estado vacío');
    await navigate('/gastos');
    for (const [width, height] of [[768, 1024], [390, 844], [320, 740]]) {
        await viewport(width, height, width < 700);
        await noOverflow(`Panel ${width}px`);
        await screenshot(`gastos-${width}px.png`);
        await click('[data-edit-expense]');
        await waitFor('document.querySelector("#expense-dialog").open');
        const box = await evaluate('(() => { const r = document.querySelector("#expense-dialog").getBoundingClientRect(); return {left:r.left,right:r.right,width:innerWidth,height:r.height,viewport:innerHeight}; })()');
        assert(box.left >= 0 && box.right <= box.width && box.height <= box.viewport, 'El modal debe caber en la pantalla');
        await screenshot(`modal-${width}px.png`);
        await click('#expense-dialog [data-close-dialog]');
        report.push(`Modal ${width}px: visible y operable`);
    }
    await click('form[action$="/logout"] button');
    await waitFor('location.pathname === "/login"');
    await noOverflow('Login móvil 320px');
    await screenshot('login-mobile.png');
    await viewport(1440, 1000);
    await login('maria_prueba');
    await navigate(cashPath);
    await navigate(`/gastos?petty_cash_account_id=${cashPath.split('/').pop()}`);
    await click('[data-edit-expense]');
    await waitFor('document.querySelector("#expense-dialog").open');
    await fill('#expense-form [name=amount]', '115.00');
    await evaluate('document.querySelector("#expense-form").requestSubmit()');
    await waitFor('document.body.innerText.includes("Gasto actualizado.")');
    await navigate(cashPath);
    assert(await evaluate('document.body.innerText.includes("15.00 adelantados")'));
    assert.equal(await evaluate('document.querySelectorAll("[data-modal-open=\\"funding-dialog\\"]").length'), 0);
    report.push('Técnico registra adelanto de 15.00 y no puede entregar dinero');
    await click('form[action$="/logout"] button');
    await waitFor('location.pathname === "/login"');
    await login('prueba_admin');
    await navigate(cashPath);
    await click('[data-modal-open="funding-dialog"]');
    await waitFor('document.querySelector("#funding-dialog").open');
    await fill('#funding-dialog [name=amount]', '100');
    assert(await evaluate('document.querySelector("[data-funding-preview]").textContent.includes("15.00") && document.querySelector("[data-funding-preview]").textContent.includes("85.00")'));
    await screenshot('reposicion-desktop.png');
    await evaluate('document.querySelector("#funding-dialog form").requestSubmit()');
    await waitFor('document.body.innerText.includes("Entrega registrada.")');
    assert(await evaluate('document.body.innerText.includes("85.00") && document.body.innerText.includes("15.00")'));
    await screenshot('caja-chica-desktop.png');
    report.push('Administrador entrega 100: reembolso 15 y disponible 85 sin duplicar el gasto');
    await click('form[action$="/logout"] button');
    await waitFor('location.pathname === "/login"');
    await navigate('/login');
    await fill('[name=login]', 'prueba_accountant');
    await fill('[name=password]', password);
    await evaluate('document.querySelector("form").requestSubmit()');
    await waitFor('location.pathname === "/gastos" && document.readyState === "complete"');
    assert.equal(await evaluate('document.querySelectorAll("[data-new-expense]").length'), 0);
    await navigate(cashPath);
    assert.equal(await evaluate('document.querySelectorAll("[data-modal-open=\\"funding-dialog\\"]").length'), 0);
    await click('[data-modal-open="review-dialog"]');
    await waitFor('document.querySelector("#review-dialog").open');
    await fill('#review-dialog [name=note]', 'Documentos fiscalizados por contador');
    await evaluate('document.querySelector("#review-dialog form").requestSubmit()');
    await waitFor('document.body.innerText.includes("Revisión guardada.")');
    report.push('Contador fiscaliza y revisa sin entregar dinero ni editar gastos');
    for (const format of ['xlsx', 'pdf']) {
        const download = await evaluate(`(async () => { const response = await fetch(${JSON.stringify(cashPath)} + '/exportar/${format}'); const bytes = new Uint8Array(await response.arrayBuffer()); return {status:response.status, data:btoa(Array.from(bytes, byte => String.fromCharCode(byte)).join(''))}; })()`);
        assert.equal(download.status, 200);
        const data = Buffer.from(download.data, 'base64');
        assert(format === 'pdf' ? data.subarray(0, 5).toString() === '%PDF-' : data.subarray(0, 2).toString() === 'PK');
        await fs.writeFile(path.join(results, `rendicion-completa.${format}`), data);
    }
    report.push('Rendición completa descargada en Excel de plantilla y PDF');
    await viewport(390, 844, true);
    await noOverflow('Caja chica móvil');
    await screenshot('caja-chica-mobile.png');
    await click('form[action$="/logout"] button');
    await waitFor('location.pathname === "/login"');
    await login('prueba_superadmin');
    await viewport(390, 844, true);
    await navigate('/configuracion');
    await noOverflow('Catálogos móvil');
    await screenshot('catalogos-mobile.png');
    for (const kind of ['category', 'receipt_type', 'branch']) {
        await navigate(`/configuracion?kind=${kind}`);
        assert.equal(await evaluate('getComputedStyle(document.querySelector(".catalog-table")).display'), 'table');
        await noOverflow(`Catálogo ${kind} móvil claro`);
        await screenshot(`ficon-${kind}-mobile-light.png`);
        await click('[data-theme-toggle]');
        assert.equal(await evaluate('document.documentElement.dataset.theme'), 'dark');
        await noOverflow(`Catálogo ${kind} móvil oscuro`);
        await screenshot(`ficon-${kind}-mobile-dark.png`);
        await click('[data-theme-toggle]');
    }
    await viewport(1440, 1000);
    await navigate('/configuracion');
    await click('[data-theme-toggle]');
    await screenshot('ficon-configuracion-dark.png');
    await navigate('/gastos');
    await screenshot('ficon-gastos-dark.png');
    await click('[data-new-expense]');
    await waitFor('document.querySelector("#expense-dialog").open');
    await screenshot('ficon-modal-dark.png');
    await click('#expense-dialog [data-close-dialog]');
    report.push('Tablas y modal revisados en tema oscuro');
    assert.equal(errors.length, 0, `Errores JavaScript: ${errors.join(', ')}`);
    report.push('Sin excepciones JavaScript durante los flujos');
    await fs.writeFile(path.join(results, 'browser-report.json'), JSON.stringify({ passed: true, checks: report, errors }, null, 2));
    console.log(JSON.stringify({ passed: true, checks: report.length, details: report }, null, 2));
} catch (error) {
    await fs.writeFile(path.join(results, 'browser-report.json'), JSON.stringify({ passed: false, checks: report, error: error.message, errors }, null, 2));
    console.error(error);
    process.exitCode = 1;
} finally {
    if (socket) socket.close();
    server.kill();
}
