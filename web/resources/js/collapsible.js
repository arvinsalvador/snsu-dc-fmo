export function initializeCollapsibles(root = document) {
    root.querySelectorAll('details[data-collapsible]').forEach(details => {
        const summary = details.querySelector(':scope > [data-collapsible-summary]');
        if (!summary) return;

        const synchronize = () => summary.setAttribute('aria-expanded', String(details.open));
        synchronize();
        details.addEventListener('toggle', synchronize);
    });
}
