import { initializeLocationSelects } from './work-order-location.js';
import { initializeCollapsibles } from './collapsible.js';

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
                placeholder.className = 'grid aspect-[4/3] place-items-center bg-slate-100 text-sm font-semibold text-slate-600';
                placeholder.textContent = 'Video file';
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

export function initializeGuidedDescription(container) {
    const actions = new Map(JSON.parse(container.dataset.actions || '[]').map(item => [item.code, item]));
    const outcomes = new Map(JSON.parse(container.dataset.outcomes || '[]').map(item => [item.code, item]));
    const summary = container.querySelector('[data-guided-summary]');
    const otherField = container.querySelector('[data-other-action-field]');
    const otherInput = otherField?.querySelector('input');
    const useButton = container.querySelector('[data-guided-use]');
    const editButton = container.querySelector('[data-guided-edit]');
    let lastGenerated = '';
    const naturalJoin = parts => parts.length < 2 ? (parts[0] || '') : parts.length === 2 ? parts.join(' and ') : `${parts.slice(0, -1).join(', ')}, and ${parts.at(-1)}`;
    const generate = () => {
        const selectedInputs = [...container.querySelectorAll('input[name="guided_actions[]"]:checked')];
        const hasOther = selectedInputs.some(input => input.value === 'OTHER_ACTION');
        otherField?.classList.toggle('hidden', !hasOther);
        if (otherInput) otherInput.required = hasOther;
        const selected = selectedInputs.map(input => input.value === 'OTHER_ACTION' && otherInput?.value.trim()
            ? otherInput.value.trim().replace(/[.\s]+$/, '')
            : actions.get(input.value)?.clause).filter(Boolean);
        const outcomeInput = container.querySelector('input[name="guided_outcome"]:checked, input[name="guided_outcome"][type="hidden"]');
        const outcome = outcomeInput ? outcomes.get(outcomeInput.value) : null;
        if (!selected.length || !outcome) return;
        const work = naturalJoin(selected);
        const actionSentence = `${work.charAt(0).toUpperCase()}${work.slice(1)}.`;
        const form = container.closest('form');
        const context = container.dataset.guidedContext;
        let generated = `${actionSentence} ${outcome.clause}`;
        if (context === 'stop') {
            generated = `${actionSentence} ${form.querySelector('input[name="stop_reason"]:checked')?.value === 'AREA_UNAVAILABLE' ? 'Work is paused because the area is temporarily unavailable.' : 'Additional work remains and will continue during the next work period.'}`;
        } else if (context === 'blocked') {
            const reason = form.querySelector('input[name="blocker_reason"]:checked')?.value;
            const material = form.querySelector('input[name="material_description"]')?.value.trim();
            const blocker = ['MATERIALS_UNAVAILABLE', 'REPLACEMENT_PART'].includes(reason)
                ? material ? `Work cannot continue because ${material} is required. The task will resume once the material is available.` : 'Work cannot continue because replacement materials are required. The task will resume once the necessary materials are available.'
                : ['EXTERNAL_SERVICE', 'FURTHER_INSPECTION'].includes(reason) ? 'The exact cause could not yet be confirmed. Further investigation is required before the repair can continue.'
                    : reason === 'TOOLS_UNAVAILABLE' ? 'Work is paused because the required tools or equipment are unavailable.'
                        : reason === 'NO_ACCESS' ? 'Work is paused because the area cannot currently be accessed.'
                            : reason === 'NEED_STAFF_HELP' ? 'Work is paused until help from another staff member is available.'
                                : reason === 'SAFETY' ? 'Work is paused because of a safety concern.' : 'Work is paused until the reported blocker is resolved.';
            generated = `${actionSentence} ${blocker}`;
        } else if (context === 'contribution') {
            generated = `${actionSentence} My assigned work is complete.`;
        } else if (context === 'complete') {
            generated = `${actionSentence} All required work is complete and ready for FMO verification.`;
        }
        if (!summary.value.trim() || summary.value === lastGenerated) summary.value = generated;
        lastGenerated = generated;
        container.dispatchEvent(new CustomEvent('guided-summary-changed', { bubbles: true, detail: { generated } }));
    };
    container.querySelectorAll('input[name="guided_actions[]"], input[name="guided_outcome"]').forEach(input => input.addEventListener('change', generate));
    otherInput?.addEventListener('input', generate);
    useButton?.addEventListener('click', () => {
        if (lastGenerated) summary.value = lastGenerated;
        summary.focus();
        container.dispatchEvent(new CustomEvent('guided-summary-changed', { bubbles: true, detail: { generated: lastGenerated } }));
    });
    editButton?.addEventListener('click', () => { summary.focus(); summary.setSelectionRange(summary.value.length, summary.value.length); });
    summary.addEventListener('input', () => container.dispatchEvent(new CustomEvent('guided-summary-changed', { bubbles: true, detail: { generated: lastGenerated } })));
    generate();
}

export function initializeWorkActionGuide(container) {
    const chooser = container.querySelector('[data-work-action-chooser]');
    const panels = [...container.querySelectorAll('[data-work-action-panel]')];
    const show = action => {
        chooser.classList.toggle('hidden', Boolean(action));
        panels.forEach(panel => panel.classList.toggle('hidden', panel.dataset.workActionPanel !== action));
        if (action) {
            const panel = panels.find(item => item.dataset.workActionPanel === action);
            panel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };
    container.querySelectorAll('[data-work-action-choice]').forEach(button => button.addEventListener('click', () => show(button.dataset.workActionChoice)));
    container.querySelectorAll('[data-work-action-back]').forEach(button => button.addEventListener('click', () => show(null)));

    container.querySelectorAll('input[name="stop_reason"]').forEach(input => input.addEventListener('change', () => {
        const form = input.form;
        const paused = input.value === 'AREA_UNAVAILABLE';
        form.querySelector('input[name="outcome"]').value = paused ? 'PAUSED' : 'CONTINUATION';
        form.querySelector('textarea[name="remaining_work"]').required = !paused;
    }));

    container.querySelectorAll('input[name="blocker_reason"]').forEach(input => input.addEventListener('change', () => {
        const form = input.form;
        const material = ['MATERIALS_UNAVAILABLE', 'REPLACEMENT_PART'].includes(input.value);
        const investigation = ['EXTERNAL_SERVICE', 'FURTHER_INSPECTION'].includes(input.value);
        form.querySelector('input[name="outcome"]').value = material ? 'WAITING_FOR_MATERIALS' : investigation ? 'NEEDS_FURTHER_INVESTIGATION' : 'PAUSED';
        form.querySelector('input[name="guided_outcome"]').value = material ? 'WAITING_SUPPLIES' : investigation ? 'INVESTIGATION' : 'PROBLEM_PERSISTS';
        const materialField = form.querySelector('[data-material-field]');
        materialField.classList.toggle('hidden', !material);
        materialField.querySelector('input').required = material;
        form.querySelector('[data-guided-description] input[name="guided_outcome"]').dispatchEvent(new Event('change', { bubbles: true }));
    }));
    container.querySelectorAll('input[name="material_description"]').forEach(input => input.addEventListener('input', () => {
        input.form.querySelector('[data-guided-description] input[name="guided_outcome"]').dispatchEvent(new Event('change', { bubbles: true }));
    }));

    container.querySelectorAll('[data-final-review-button]').forEach(button => button.addEventListener('click', () => {
        const form = button.form;
        const actions = [...form.querySelectorAll('input[name="guided_actions[]"]:checked')];
        const firstAction = form.querySelector('input[name="guided_actions[]"]');
        firstAction?.setCustomValidity(actions.length ? '' : 'Please select at least one action you completed.');
        if (!form.reportValidity()) return;
        const summary = form.querySelector('[data-guided-summary]').value;
        form.querySelector('input[name="completion_summary"]').value = summary;
        form.querySelector('input[name="work_performed_summary"]').value = summary;
        const list = form.querySelector('[data-final-actions]');
        list.replaceChildren(...actions.map(input => {
            const item = document.createElement('li');
            item.className = 'flex gap-2 text-sm text-slate-700';
            item.textContent = `✓ ${input.closest('label').innerText.trim()}`;
            return item;
        }));
        form.querySelector('[data-final-summary]').textContent = summary;
        const photos = [...form.querySelectorAll('[data-photo-input]')].reduce((count, input) => count + input.files.length, 0);
        form.querySelector('[data-final-photo-count]').textContent = `${photos} ${photos === 1 ? 'photo' : 'photos'} attached`;
        form.querySelector('[data-final-builder]').classList.add('hidden');
        form.querySelector('[data-final-review]').classList.remove('hidden');
        const confirmation = form.querySelector('[data-final-confirm]');
        confirmation.disabled = false;
        confirmation.required = true;
        form.querySelector('[data-final-review]').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
    container.querySelectorAll('[data-final-review-back]').forEach(button => button.addEventListener('click', () => {
        const form = button.form;
        const confirmation = form.querySelector('[data-final-confirm]');
        confirmation.required = false;
        confirmation.disabled = true;
        confirmation.checked = false;
        form.querySelector('[data-final-review]').classList.add('hidden');
        form.querySelector('[data-final-builder]').classList.remove('hidden');
    }));

    container.querySelectorAll('input[name="stop_reason"]:checked, input[name="blocker_reason"]:checked').forEach(input => input.dispatchEvent(new Event('change', { bubbles: true })));

    show(container.dataset.initialAction || null);
}

export function initializeRoutineAssignmentModal() {
    const modal = document.querySelector('[data-assignment-modal]');
    if (!modal) return;

    const panel = modal.querySelector('[data-assignment-panel]');
    const form = modal.querySelector('[data-assignment-form]');
    const title = modal.querySelector('[data-assignment-title]');
    const meta = modal.querySelector('[data-assignment-meta]');
    const templateId = modal.querySelector('[data-assignment-template-id]');
    const note = modal.querySelector('[data-assignment-note]');
    const search = modal.querySelector('[data-assignment-search]');
    const error = modal.querySelector('[data-assignment-error]');
    const empty = modal.querySelector('[data-personnel-empty]');
    const cards = [...modal.querySelectorAll('[data-personnel-card]')];
    const buttons = [...document.querySelectorAll('[data-assignment-open]')];
    let previousFocus = null;

    const selectedIds = button => {
        try { return JSON.parse(button.dataset.assignmentIds || '[]').map(String); }
        catch { return []; }
    };
    const show = (button, preserveSubmittedValues = false) => {
        previousFocus = document.activeElement;
        form.action = button.dataset.action;
        templateId.value = button.dataset.templateId;
        title.textContent = `Assign Team — ${button.dataset.title}`;
        meta.textContent = button.dataset.meta || '';
        if (!preserveSubmittedValues) {
            const assigned = selectedIds(button);
            cards.forEach(card => {
                const checkbox = card.querySelector('input[type="checkbox"]');
                if (!checkbox.disabled) checkbox.checked = assigned.includes(checkbox.value);
            });
            note.value = button.dataset.note || '';
            error.classList.add('hidden');
            error.textContent = '';
        }
        search.value = '';
        cards.forEach(card => card.classList.remove('hidden'));
        empty.classList.add('hidden');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        window.requestAnimationFrame(() => search.focus());
    };
    const close = () => {
        if (form.dataset.saving === 'true') return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
        previousFocus?.focus();
    };

    buttons.forEach(button => button.addEventListener('click', () => show(button)));
    modal.querySelectorAll('[data-assignment-close]').forEach(button => button.addEventListener('click', close));
    search.addEventListener('input', () => {
        const query = search.value.trim().toLowerCase();
        let visible = 0;
        cards.forEach(card => {
            const matches = !query || card.dataset.search.includes(query);
            card.classList.toggle('hidden', !matches);
            if (matches) visible += 1;
        });
        empty.classList.toggle('hidden', visible !== 0);
    });
    form.addEventListener('submit', event => {
        const hasSelection = cards.some(card => {
            const checkbox = card.querySelector('input[type="checkbox"]');
            return !checkbox.disabled && checkbox.checked;
        });
        if (!hasSelection) {
            event.preventDefault();
            error.textContent = 'Select at least one available personnel member.';
            error.classList.remove('hidden');
            error.setAttribute('tabindex', '-1');
            error.focus();
            return;
        }
        form.dataset.saving = 'true';
    });
    document.addEventListener('keydown', event => {
        if (modal.classList.contains('hidden')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = [...panel.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), a[href]')]
            .filter(element => !element.closest('.hidden'));
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    const reopenId = modal.dataset.reopenTemplate;
    if (reopenId && modal.dataset.serverErrors === 'true') {
        const button = buttons.find(candidate => candidate.dataset.templateId === reopenId);
        if (button) show(button, true);
    }
}

export function initializeAssetQrModal() {
    const modal = document.querySelector('[data-asset-qr-modal]');
    if (!modal) return;

    const panel = modal.querySelector('[data-asset-qr-panel]');
    const label = modal.querySelector('#asset-qr-label');
    const printButton = modal.querySelector('[data-asset-qr-print]');
    const openButtons = [...document.querySelectorAll('[data-asset-qr-open]')];
    let previousFocus = null;
    let printRoot = null;

    const open = () => {
        previousFocus = document.activeElement;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden');
        window.requestAnimationFrame(() => modal.querySelector('[data-asset-qr-close][aria-label]')?.focus());
    };
    const close = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
        previousFocus?.focus();
    };
    const cleanPrintRoot = () => {
        printRoot?.remove();
        printRoot = null;
        document.body.classList.remove('asset-qr-printing');
    };
    const printLabel = () => {
        cleanPrintRoot();
        printRoot = document.createElement('div');
        printRoot.id = 'asset-qr-print-root';
        printRoot.setAttribute('aria-hidden', 'true');
        printRoot.append(label.cloneNode(true));
        printRoot.querySelector('#asset-qr-label')?.removeAttribute('id');
        document.body.append(printRoot);
        document.body.classList.add('asset-qr-printing');
        window.requestAnimationFrame(() => {
            window.print();
            window.setTimeout(cleanPrintRoot, 0);
        });
    };

    openButtons.forEach(button => button.addEventListener('click', open));
    modal.querySelectorAll('[data-asset-qr-close]').forEach(button => button.addEventListener('click', close));
    printButton?.addEventListener('click', printLabel);
    window.addEventListener('afterprint', cleanPrintRoot);
    document.addEventListener('keydown', event => {
        if (modal.classList.contains('hidden')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = [...panel.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])')];
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initializeCollapsibles();
    document.querySelectorAll('[data-work-order-location]').forEach(initializeLocationSelects);
    document.querySelectorAll('[data-photo-picker]').forEach(initializePhotoPicker);
    document.querySelectorAll('[data-guided-description]').forEach(initializeGuidedDescription);
    document.querySelectorAll('[data-work-action-guide]').forEach(initializeWorkActionGuide);
    initializeRoutineAssignmentModal();
    initializeAssetQrModal();
    initializeEvidenceViewer();
    const drawer = document.querySelector('[data-sidebar]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const toggles = [...document.querySelectorAll('[data-sidebar-toggle]')];
    const setExpanded = expanded => toggles.forEach(toggle => toggle.setAttribute('aria-expanded', String(expanded)));
    const close = () => { drawer?.classList.add('-translate-x-full'); backdrop?.classList.add('hidden'); setExpanded(false); };
    const open = () => { drawer?.classList.remove('-translate-x-full'); backdrop?.classList.remove('hidden'); setExpanded(true); };
    toggles.forEach(toggle => toggle.addEventListener('click', () => drawer?.classList.contains('-translate-x-full') ? open() : close()));
    backdrop?.addEventListener('click', close);
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && drawer && !drawer.classList.contains('-translate-x-full') && window.innerWidth < 1024) close();
    });

    document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        button.textContent = visible ? 'Show' : 'Hide';
        button.setAttribute('aria-pressed', String(!visible));
    }));

    const confirmDialog = document.querySelector('[data-confirm-dialog]');
    const confirmMessage = confirmDialog?.querySelector('[data-confirm-message]');
    const confirmSubmit = confirmDialog?.querySelector('[data-confirm-submit]');
    let pendingForm = null;
    let previousFocus = null;
    const closeConfirm = () => {
        confirmDialog?.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        previousFocus?.focus();
        pendingForm = null;
    };
    document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (form.dataset.confirmed === 'true') return;
        event.preventDefault();
        pendingForm = form;
        previousFocus = document.activeElement;
        if (confirmMessage) confirmMessage.textContent = form.dataset.confirm;
        confirmDialog?.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        confirmSubmit?.focus();
    }));
    confirmDialog?.querySelectorAll('[data-confirm-cancel]').forEach(button => button.addEventListener('click', closeConfirm));
    confirmSubmit?.addEventListener('click', () => {
        if (!pendingForm) return;
        pendingForm.dataset.confirmed = 'true';
        pendingForm.requestSubmit();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && confirmDialog && !confirmDialog.classList.contains('hidden')) closeConfirm();
    });

    document.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => {
        const submit = form.querySelector('button[type="submit"], button:not([type])');
        if (submit && !submit.dataset.noLoading && (!form.hasAttribute('data-confirm') || form.dataset.confirmed === 'true')) {
            submit.disabled = true;
            submit.replaceChildren(document.createTextNode(submit.dataset.loadingText || 'Please wait…'));
        }
    }));
});
