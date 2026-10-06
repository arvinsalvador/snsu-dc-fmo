import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeLocationSelects } from '../../resources/js/work-order-location.js';

class Select {
    constructor(value = '') { this.value = value; this.options = []; this.listeners = {}; this.disabled = true; }
    replaceChildren(option) { this.options = [option]; this.value = ''; }
    add(option) { this.options.push(option); }
    get selectedOptions() { return this.options.filter(option => option.value === this.value); }
    addEventListener(event, handler) { this.listeners[event] = handler; }
    change(value) { this.value = value; return this.listeners.change(); }
}

function fixture(initial = {}, responses = {}) {
    globalThis.Option = class { constructor(text, value) { this.textContent = text; this.value = value; } };
    globalThis.window = { location: { origin: 'http://localhost' } };
    const controls = Object.fromEntries(['campus', 'building', 'floor', 'location'].map(key => [key, new Select(initial[key] || '')]));
    controls.campus.options = [new Option('Select campus', ''), new Option('Campus A', 'A'), new Option('Campus B', 'B'), new Option('Campus C', 'C')];
    const summary = { classList: { toggle() {} } };
    const summaryText = { textContent: '' };
    const root = {
        dataset: { buildingsUrl: '/buildings', floorsUrl: '/floors', areasUrl: '/areas' },
        querySelector: key => key === '#location-summary' ? summary : key === '[data-location-summary]' ? summaryText : controls[key.slice(1)],
    };
    const requests = [];
    globalThis.fetch = async url => {
        requests.push(url);
        const key = url.pathname + ':' + (url.searchParams.get('campus_id') || url.searchParams.get('building_id'));
        const value = responses[key] || [];
        if (typeof value === 'function') return value();
        return { ok: true, json: async () => ({ data: value }) };
    };
    return { root, controls, requests, summaryText };
}

const choices = {
    '/buildings:A': [{ id: 'A1', name: 'Academic' }, { id: 'A2', name: 'Administration' }],
    '/buildings:B': [{ id: 'B1', name: 'Other Building' }],
    '/floors:A1': [{ id: 'G', name: 'Ground Floor' }, { id: 'S', name: 'Second Floor' }],
    '/areas:A1': [{ id: 'gate', name: 'Main Gate', floor_id: null }, { id: 'lab', name: 'Laboratory', floor_id: 'G' }, { id: 'room', name: 'Room 201', floor_id: 'S' }],
};

test('campus fetch enables scoped buildings and changing campus clears every child', async () => {
    const f = fixture({}, choices);
    await initializeLocationSelects(f.root);
    assert.equal(f.controls.building.disabled, true);
    await f.controls.campus.change('A');
    assert.equal(f.requests[0].searchParams.get('campus_id'), 'A');
    assert.equal(f.controls.building.disabled, false);
    assert.deepEqual(f.controls.building.options.map(option => option.value), ['', 'A1', 'A2']);
    await f.controls.building.change('A1');
    await f.controls.floor.change('G');
    await f.controls.location.change('lab');
    await f.controls.campus.change('B');
    assert.deepEqual(f.controls.building.options.map(option => option.value), ['', 'B1']);
    assert.equal(f.controls.floor.value, '');
    assert.equal(f.controls.location.value, '');
    assert.equal(f.controls.floor.disabled, true);
});

test('floor filters rooms and preserves building-level areas', async () => {
    const f = fixture({ campus: 'A', building: 'A1', floor: 'G', location: 'lab' }, choices);
    await initializeLocationSelects(f.root);
    assert.equal(f.controls.building.value, 'A1');
    assert.equal(f.controls.floor.value, 'G');
    assert.equal(f.controls.location.value, 'lab');
    assert.match(f.summaryText.textContent, /Campus A · Academic · Ground Floor · Laboratory/);
    await f.controls.floor.change('S');
    assert.deepEqual(f.controls.location.options.map(option => option.value), ['', 'gate', 'room']);
    assert.equal(f.controls.location.value, '');
    await f.controls.floor.change('');
    assert.deepEqual(f.controls.location.options.map(option => option.value), ['', 'gate']);
});

test('empty campus and buildings without floors never remain loading', async () => {
    const f = fixture({}, choices);
    await initializeLocationSelects(f.root);
    await f.controls.campus.change('C');
    assert.equal(f.controls.building.disabled, true);
    assert.match(f.controls.building.options[0].textContent, /No active buildings/);
    await f.controls.campus.change('B');
    await f.controls.building.change('B1');
    assert.match(f.controls.floor.options[0].textContent, /No active floors/);
    assert.equal(f.controls.building.value, 'B1');
});

test('failed lookup shows a retry state', async () => {
    const originalError = console.error;
    console.error = () => {};
    try {
        const f = fixture({}, { '/buildings:A': () => ({ ok: false, status: 403 }) });
        await initializeLocationSelects(f.root);
        await f.controls.campus.change('A');
        assert.equal(f.controls.building.disabled, true);
        assert.match(f.controls.building.options[0].textContent, /Unable to load buildings/);
    } finally { console.error = originalError; }
});

test('slow responses cannot restore stale buildings after a campus change', async () => {
    let resolveA;
    const f = fixture({}, { ...choices, '/buildings:A': () => new Promise(resolve => { resolveA = resolve; }) });
    await initializeLocationSelects(f.root);
    const first = f.controls.campus.change('A');
    await f.controls.campus.change('B');
    resolveA({ ok: true, json: async () => ({ data: choices['/buildings:A'] }) });
    await first;
    assert.deepEqual(f.controls.building.options.map(option => option.value), ['', 'B1']);
});
