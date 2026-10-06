export function initializeLocationSelects(root) {
    const campusSelect = root.querySelector('#campus');
    const buildingSelect = root.querySelector('#building');
    const floorSelect = root.querySelector('#floor');
    const areaSelect = root.querySelector('#location');
    const summary = root.querySelector('#location-summary');
    const summaryText = root.querySelector('[data-location-summary]');
    const initial = { building: buildingSelect.value, floor: floorSelect.value, area: areaSelect.value };
    let campusVersion = 0;
    let buildingVersion = 0;
    let areaOptions = [];

    const reset = (select, text) => {
        select.replaceChildren(new Option(text, ''));
        select.disabled = true;
    };
    const populate = (select, items, placeholder, emptyText, selected = '') => {
        reset(select, items.length ? placeholder : emptyText);
        items.forEach(item => select.add(new Option(item.name, String(item.id))));
        select.disabled = items.length === 0;
        select.value = items.some(item => String(item.id) === String(selected)) ? String(selected) : '';
    };
    const updateSummary = () => {
        const parts = [campusSelect, buildingSelect, floorSelect, areaSelect]
            .filter(select => select.value)
            .map(select => select.selectedOptions[0].textContent.trim());
        summary.classList.toggle('hidden', !buildingSelect.value);
        summaryText.textContent = parts.join(' · ');
    };
    const lookup = async (endpoint, parameters) => {
        const url = new URL(endpoint, window.location.origin);
        Object.entries({ ...parameters, active: '1' }).forEach(([key, value]) => url.searchParams.set(key, value));
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`Location lookup failed (${response.status})`);
        const result = await response.json();
        if (!Array.isArray(result.data)) throw new Error('Invalid location lookup response');
        return result.data;
    };
    const filterAreas = (selected = '') => {
        const applicable = areaOptions.filter(area => !area.floor_id || String(area.floor_id) === floorSelect.value);
        populate(areaSelect, applicable, 'Select location (optional)', 'No active areas available', selected);
        updateSummary();
    };
    const loadBuilding = async (restore = {}) => {
        const version = ++buildingVersion;
        areaOptions = [];
        reset(floorSelect, buildingSelect.value ? 'Loading floors…' : 'Select a building first');
        reset(areaSelect, buildingSelect.value ? 'Loading areas…' : 'Select a building first');
        updateSummary();
        if (!buildingSelect.value) return;
        const parameters = { building_id: buildingSelect.value };
        await Promise.all([
            (async () => {
                try {
                    const floors = await lookup(root.dataset.floorsUrl, parameters);
                    if (version !== buildingVersion) return;
                    populate(floorSelect, floors, 'Select floor (optional)', 'No active floors available', restore.floor);
                    filterAreas(restore.area);
                } catch (error) {
                    if (version !== buildingVersion) return;
                    reset(floorSelect, 'Unable to load floors. Select the building again to retry.');
                    console.error(error);
                }
            })(),
            (async () => {
                try {
                    const areas = await lookup(root.dataset.areasUrl, parameters);
                    if (version !== buildingVersion) return;
                    areaOptions = areas;
                    filterAreas(restore.area);
                } catch (error) {
                    if (version !== buildingVersion) return;
                    reset(areaSelect, 'Unable to load areas. Select the building again to retry.');
                    console.error(error);
                }
            })(),
        ]);
        updateSummary();
    };
    const loadCampus = async (restore = {}) => {
        const version = ++campusVersion;
        ++buildingVersion;
        areaOptions = [];
        reset(buildingSelect, campusSelect.value ? 'Loading buildings…' : 'Select a campus first');
        reset(floorSelect, 'Select a building first');
        reset(areaSelect, 'Select a building first');
        updateSummary();
        if (!campusSelect.value) return;
        try {
            const buildings = await lookup(root.dataset.buildingsUrl, { campus_id: campusSelect.value });
            if (version !== campusVersion) return;
            populate(buildingSelect, buildings, 'Select building', 'No active buildings available', restore.building);
            if (buildingSelect.value) await loadBuilding(restore);
        } catch (error) {
            if (version !== campusVersion) return;
            reset(buildingSelect, 'Unable to load buildings. Select the campus again to retry.');
            console.error(error);
        }
    };

    campusSelect.addEventListener('change', () => loadCampus());
    buildingSelect.addEventListener('change', () => loadBuilding());
    floorSelect.addEventListener('change', () => filterAreas());
    areaSelect.addEventListener('change', updateSummary);
    return loadCampus(initial);
}
