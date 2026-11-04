document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
document.querySelectorAll('dialog').forEach(dialog => {
    dialog.addEventListener('click', event => { if (event.target === dialog && event.offsetX >= 0 && event.offsetY >= 0) { const box = dialog.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close(); } });
    if (dialog.hasAttribute('data-reopen')) dialog.showModal();
});

function configureForm(kind, data) {
    const dialog = document.getElementById(`${kind}-dialog`);
    const form = document.getElementById(`${kind}-form`);
    form.reset();
    const editing = Boolean(data?.id);
    form.action = editing ? `${form.dataset.updateBase}/${data.id}` : form.dataset.createUrl;
    form.elements._method.disabled = !editing;
    form.elements[`_${kind}_id`].value = editing ? data.id : '';
    if (kind === 'expense') {
        document.getElementById('expense-dialog-title').textContent = editing ? 'Editar gasto' : 'Registrar gasto';
        form.elements.version.value = editing ? data.version : '';
        if (editing) ['spent_on', 'description', 'category', 'amount'].forEach(key => { form.elements[key].value = data[key]; });
        else { form.elements.description.value = ''; form.elements.amount.value = ''; form.elements.category.value = 'Otros'; }
        ['provider', 'tax_id', 'receipt_type_id', 'series', 'document_number', 'observation'].forEach(key => { form.elements[key].value = editing ? (data[key] ?? '') : ''; });
        form.elements.petty_cash_account_id.value = editing ? (data.petty_cash_account_id ?? '') : (form.dataset.defaultCash || '');
        ['category', 'receipt_type_id'].forEach(key => [...form.elements[key].options].forEach(option => { option.disabled = option.dataset.inactive === '1' && (!editing || option.value !== String(data[key] ?? '')); }));
        form.elements.receipt.value = '';
        document.getElementById('current-receipt').textContent = editing && data.receipt_name ? `Actual: ${data.receipt_name}. Si adjuntas otro, se guardará una nueva versión.` : '';
    } else if (kind === 'user') {
        document.getElementById('user-dialog-title').textContent = editing ? 'Editar usuario' : 'Crear usuario';
        ['name', 'username', 'email'].forEach(key => { form.elements[key].value = editing ? data[key] : ''; });
        form.elements.role.value = editing ? data.role : 'technician';
        form.elements.active.value = editing && !data.active ? '0' : '1';
        ['password', 'password_confirmation'].forEach(key => { form.elements[key].value = ''; form.elements[key].required = !editing; });
    } else if (kind === 'catalog') {
        form.elements.name.value = editing ? data.name : '';
        form.elements.sort_order.value = editing ? data.sort_order : '0';
        form.elements.active.value = editing && !data.active ? '0' : '1';
        form.elements.version.value = editing ? data.version : '';
    }
    dialog.showModal();
}
document.querySelectorAll('[data-new-expense]').forEach(button => button.addEventListener('click', () => configureForm('expense')));
document.querySelectorAll('[data-edit-expense]').forEach(button => button.addEventListener('click', () => configureForm('expense', JSON.parse(button.dataset.editExpense))));
document.querySelectorAll('[data-new-user]').forEach(button => button.addEventListener('click', () => configureForm('user')));
document.querySelectorAll('[data-edit-user]').forEach(button => button.addEventListener('click', () => configureForm('user', JSON.parse(button.dataset.editUser))));
document.querySelectorAll('[data-new-catalog]').forEach(button => button.addEventListener('click', () => configureForm('catalog')));
document.querySelectorAll('[data-edit-catalog]').forEach(button => button.addEventListener('click', () => configureForm('catalog', JSON.parse(button.dataset.editCatalog))));
document.querySelectorAll('[data-modal-open]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.modalOpen).showModal()));
document.querySelectorAll('form[method="post"]').forEach(form => form.addEventListener('submit', () => {
    const button = form.querySelector('button[type="submit"]');
    if (button) { button.disabled = true; button.textContent = 'Guardando…'; }
}));
document.querySelectorAll('[data-funding-amount]').forEach(input => input.addEventListener('input', () => {
    const amount = Math.round(Number(input.value || 0) * 100);
    const debt = Math.round(Number(input.dataset.advance) * 100);
    const reimbursement = Math.min(Math.max(0, amount), debt);
    const available = Math.max(0, amount - reimbursement);
    input.closest('form').querySelector('[data-funding-preview]').textContent = `Reembolso al responsable: ${(reimbursement / 100).toFixed(2)}. Se agrega al fondo: ${(available / 100).toFixed(2)}.`;
}));
