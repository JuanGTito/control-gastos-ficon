(() => {
    const storageKey = 'gastos-ficon-theme';
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    let preference;
    try { preference = localStorage.getItem(storageKey); } catch {}
    const apply = theme => {
        document.documentElement.dataset.theme = theme;
        document.documentElement.style.colorScheme = theme;
        document.querySelectorAll('[data-theme-toggle]').forEach(button => {
            const dark = theme === 'dark';
            const label = dark ? 'Modo claro' : 'Modo oscuro';
            button.setAttribute('aria-label', `Activar ${label.toLowerCase()}`);
            button.setAttribute('aria-pressed', String(dark));
            button.querySelector('[data-theme-label]').textContent = label;
            button.querySelector('[data-theme-icon]').textContent = dark ? '☀' : '☾';
        });
    };
    const current = () => ['light', 'dark'].includes(preference) ? preference : (media.matches ? 'dark' : 'light');
    apply(current());
    document.addEventListener('DOMContentLoaded', () => {
        apply(current());
        document.querySelectorAll('[data-theme-toggle]').forEach(button => button.addEventListener('click', () => {
            preference = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem(storageKey, preference); } catch {}
            apply(preference);
        }));
    });
    media.addEventListener('change', () => { if (!['light', 'dark'].includes(preference)) apply(current()); });
    window.addEventListener('storage', event => { if (event.key === storageKey) { preference = event.newValue; apply(current()); } });
})();
