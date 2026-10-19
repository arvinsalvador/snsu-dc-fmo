export function initializePersonnelSkills(container) {
    const checkboxes = [...container.querySelectorAll('input[name="skill_ids[]"]')];
    const primary = container.querySelector('select[name="primary_skill_id"]');
    const help = container.querySelector('[data-primary-skill-help]');
    if (!primary || !checkboxes.length) return;

    const checkboxFor = value => checkboxes.find(checkbox => checkbox.value === value);
    const synchronizeOptions = () => {
        const assigned = new Set(checkboxes.filter(checkbox => checkbox.checked).map(checkbox => checkbox.value));
        [...primary.options].forEach(option => {
            if (!option.value) return;
            const available = assigned.has(option.value);
            option.hidden = !available;
            option.disabled = !available;
        });
    };
    const announce = message => {
        if (help) help.textContent = message;
    };

    primary.addEventListener('change', () => {
        if (!primary.value) {
            announce('Choose an assigned skill when a primary specialization is needed.');
            return;
        }
        const checkbox = checkboxFor(primary.value);
        if (checkbox && !checkbox.checked) checkbox.checked = true;
        synchronizeOptions();
        announce('The primary skill is included in Assigned Skills.');
    });

    checkboxes.forEach(checkbox => checkbox.addEventListener('change', () => {
        if (!checkbox.checked && primary.value === checkbox.value) {
            primary.value = '';
            announce('Select a new primary skill.');
        } else {
            announce('Choose the primary specialization from the assigned skills.');
        }
        synchronizeOptions();
    }));

    synchronizeOptions();
}
