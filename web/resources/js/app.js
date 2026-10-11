import { initializeLocationSelects } from './work-order-location.js';

export function initializePhotoPicker(container) {
    const preview = container.querySelector('[data-photo-preview]');
    const empty = container.querySelector('[data-photo-empty]');
    const error = container.querySelector('[data-photo-error]');
    const maxFiles = Number(container.dataset.maxFiles || 3);
    const maxBytes = Number(container.dataset.maxBytes || 0);
    let cameraSequence = 0;

    const inputs = () => [...container.querySelectorAll('[data-photo-input]')];
    const files = () => inputs().flatMap(input => [...input.files].map((file, index) => ({ file, input, index })));

    const removeFile = (input, removeIndex) => {
        if (input.hasAttribute('data-photo-camera')) {
            input.remove();
        } else if (typeof DataTransfer !== 'undefined') {
            const transfer = new DataTransfer();
            [...input.files].forEach((file, index) => { if (index !== removeIndex) transfer.items.add(file); });
            input.files = transfer.files;
        } else {
            input.value = '';
        }
        render();
    };

    const render = () => {
        const selected = files();
        preview.replaceChildren();
        selected.forEach(({ file, input, index }) => {
            const item = document.createElement('div');
            item.className = 'relative overflow-hidden rounded-xl border border-slate-200 bg-white';
            if (file.type.startsWith('image/')) {
                const image = document.createElement('img');
                image.src = URL.createObjectURL(file);
                image.alt = file.name;
                image.className = 'aspect-[4/3] w-full object-cover';
                image.onload = () => URL.revokeObjectURL(image.src);
                item.append(image);
            } else {
                const placeholder = document.createElement('div');
                placeholder.className = 'grid aspect-[4/3] place-items-center bg-slate-100 text-3xl';
                placeholder.textContent = '▶';
                item.append(placeholder);
            }
            const name = document.createElement('p');
            name.className = 'truncate px-2 py-2 text-xs text-slate-600';
            name.textContent = file.name;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'absolute right-2 top-2 grid size-8 place-items-center rounded-full bg-slate-950/75 text-lg text-white';
            remove.setAttribute('aria-label', `Remove ${file.name}`);
            remove.textContent = '×';
            remove.addEventListener('click', () => removeFile(input, index));
            item.append(name, remove);
            preview.append(item);
        });
        preview.classList.toggle('hidden', selected.length === 0);
        empty.classList.toggle('hidden', selected.length > 0);
        const tooMany = selected.length > maxFiles;
        const tooLarge = selected.some(({ file }) => maxBytes && file.size > maxBytes);
        error.classList.toggle('hidden', !tooMany && !tooLarge);
        error.textContent = tooMany
            ? `Choose no more than ${maxFiles} files for this submission.`
            : tooLarge ? 'One or more files exceed the allowed upload size.' : '';
    };

    const bind = input => input.addEventListener('change', () => {
        if (input.hasAttribute('data-photo-camera') && input.files.length) {
            const replacement = input.cloneNode();
            replacement.value = '';
            replacement.id = `${input.id.split('-used-')[0]}-used-${++cameraSequence}`;
            const label = container.querySelector(`label[for="${CSS.escape(input.id)}"]`);
            input.removeAttribute('id');
            input.after(replacement);
            if (label) label.htmlFor = replacement.id;
            bind(replacement);
        }
        render();
    });

    inputs().forEach(bind);
    render();
}

export function initializeEvidenceViewer() {
    const modal = document.querySelector('[data-image-lightbox]');
    if (!modal) return;
    const image = modal.querySelector('[data-lightbox-image]');
    const label = modal.querySelector('[data-lightbox-label]');
    const meta = modal.querySelector('[data-lightbox-meta]');
    const counter = modal.querySelector('[data-lightbox-counter]');
    const previous = modal.querySelector('[data-lightbox-previous]');
    const next = modal.querySelector('[data-lightbox-next]');
    const openFull = modal.querySelector('[data-lightbox-open]');
    let items = [];
    let current = 0;
    let touchStart = null;

    const show = index => {
        current = (index + items.length) % items.length;
        const item = items[current];
        image.src = item.dataset.full;
        image.alt = item.getAttribute('aria-label') || item.dataset.label;
        label.textContent = item.dataset.label;
        meta.textContent = item.dataset.meta;
        counter.textContent = `${current + 1} of ${items.length}`;
        openFull.href = item.dataset.full;
        previous.classList.toggle('hidden', items.length < 2);
        next.classList.toggle('hidden', items.length < 2);
    };
    const close = () => {
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        image.removeAttribute('src');
    };
    const open = trigger => {
        items = [...document.querySelectorAll(`[data-evidence-item][data-gallery="${CSS.escape(trigger.dataset.gallery)}"]`)];
        show(items.indexOf(trigger));
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        modal.querySelector('[data-lightbox-close]').focus();
    };

    document.querySelectorAll('[data-evidence-item]').forEach(trigger => trigger.addEventListener('click', () => open(trigger)));
    document.querySelectorAll('[data-gallery-expand]').forEach(button => button.addEventListener('click', () => {
        document.querySelectorAll(`[data-evidence-gallery="${CSS.escape(button.dataset.galleryExpand)}"] [data-gallery-extra]`).forEach(item => item.classList.remove('hidden'));
        button.remove();
    }));
    modal.querySelector('[data-lightbox-close]').addEventListener('click', close);
    modal.querySelector('[data-lightbox-backdrop]').addEventListener('click', close);
    previous.addEventListener('click', () => show(current - 1));
    next.addEventListener('click', () => show(current + 1));
    modal.querySelector('[data-lightbox-swipe]').addEventListener('pointerdown', event => { touchStart = event.clientX; });
    modal.querySelector('[data-lightbox-swipe]').addEventListener('pointerup', event => {
        if (touchStart === null || Math.abs(event.clientX - touchStart) < 50 || items.length < 2) return;
        show(current + (event.clientX < touchStart ? 1 : -1));
        touchStart = null;
    });
    document.addEventListener('keydown', event => {
        if (modal.classList.contains('hidden')) return;
        if (event.key === 'Escape') close();
        if (event.key === 'ArrowLeft' && items.length > 1) show(current - 1);
        if (event.key === 'ArrowRight' && items.length > 1) show(current + 1);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-work-order-location]').forEach(initializeLocationSelects);
    document.querySelectorAll('[data-photo-picker]').forEach(initializePhotoPicker);
    initializeEvidenceViewer();
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
