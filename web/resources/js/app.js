import { initializeLocationSelects } from './work-order-location.js';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-work-order-location]').forEach(initializeLocationSelects);
    document.querySelector('input[name="attachments[]"]')?.addEventListener('change', event => {
        const files = [...event.target.files].map(file => file.name);
        const summary = document.querySelector('#attachment-summary');
        if (summary) summary.textContent = files.length ? files.join(', ') : 'No files selected';
    });
    const drawer = document.querySelector('[data-sidebar]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const close = () => { drawer?.classList.add('-translate-x-full'); backdrop?.classList.add('hidden'); };
    const open = () => { drawer?.classList.remove('-translate-x-full'); backdrop?.classList.remove('hidden'); };
    document.querySelectorAll('[data-sidebar-toggle]').forEach(toggle => toggle.addEventListener('click', () => drawer?.classList.contains('-translate-x-full') ? open() : close()));
    backdrop?.addEventListener('click', close);
    document.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => {
        const submit = form.querySelector('button[type="submit"], button:not([type])');
        if (submit && !submit.dataset.noLoading) { submit.disabled = true; submit.textContent = 'Please wait…'; }
    }));
});
