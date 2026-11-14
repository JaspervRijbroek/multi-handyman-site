// One language bar per admin form: shows only the chosen language of every translated field.
// Without this script all languages are simply shown under each other.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form').forEach((form) => {
        const rows = form.querySelectorAll('.field-translated [data-locale]');
        const locales = [...new Set([...rows].map((row) => row.dataset.locale))];
        if (locales.length < 2) {
            return;
        }

        const bar = document.createElement('div');
        bar.className = 'btn-group mb-3';
        bar.setAttribute('role', 'group');

        const show = (locale) => {
            rows.forEach((row) => { row.hidden = row.dataset.locale !== locale; });
            bar.querySelectorAll('button').forEach((button) => {
                const active = button.dataset.locale === locale;
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', active);
            });
        };

        locales.forEach((locale) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-secondary';
            button.dataset.locale = locale;
            button.textContent = locale.toUpperCase();
            button.addEventListener('click', () => show(locale));
            bar.append(button);
        });

        // The bar already says which language is shown, so hide the per-input "EN" labels
        rows.forEach((row) => row.querySelector('label')?.setAttribute('hidden', ''));

        form.querySelector('.field-translated').before(bar);
        show(locales[0]);
    });
});
