import assert from 'node:assert/strict';
import test from 'node:test';

import { preferredPersonnelSelector } from '../../resources/js/preferred-personnel.js';

function response(data, mappingConfigured = true) {
    return {
        ok: true,
        json: async () => ({
            data,
            state: mappingConfigured ? (data.length ? 'ready' : 'no_matches') : 'unmapped',
            mapping_configured: mappingConfigured,
            max_selections: 3,
        }),
    };
}

test('changing work type removes invalid preferences and retains still-valid personnel', async () => {
    const selector = preferredPersonnelSelector({
        categoryId: 'electrical',
        lookupUrl: '/categories/__CATEGORY__/personnel',
        maxSelections: 3,
        selected: ['electrical-only', 'cross-skilled'],
    });
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => response([
        { id: 'cross-skilled', name: 'Cross Skilled', matching_skills: ['Air Conditioning'] },
        { id: 'ac-only', name: 'AC Only', matching_skills: ['Air Conditioning'] },
    ]);

    try {
        await selector.load('air-conditioning');
    } finally {
        globalThis.fetch = originalFetch;
    }

    assert.deepEqual(selector.selected, ['cross-skilled']);
    assert.match(selector.removedMessage, /removed because their skills do not match/i);
    assert.equal(selector.loading, false);
});

test('the selector enforces the configurable maximum before a forged request reaches the server', () => {
    const selector = preferredPersonnelSelector({
        maxSelections: 3,
        selected: ['one', 'two', 'three'],
    });

    selector.toggle('four');

    assert.deepEqual(selector.selected, ['one', 'two', 'three']);
    assert.equal(selector.errorMessage, 'You may select up to 3 preferred personnel.');
});

test('summary, chips, maximum state, and removal stay synchronized', () => {
    const selector = preferredPersonnelSelector({ maxSelections: 3, selected: [] });
    selector.lookupState = 'ready';
    selector.options = [
        { id: 'one', name: 'Person One' },
        { id: 'two', name: 'Person Two' },
        { id: 'three', name: 'Person Three' },
        { id: 'four', name: 'Person Four' },
    ];

    assert.equal(selector.availabilitySummary, '4 matching FMO personnel · No preference selected');

    selector.toggle('one');
    assert.equal(selector.selectionSummary, '1 of 3 preferred personnel selected · 2 selections remaining');
    assert.deepEqual(selector.selectedPeople.map(person => person.name), ['Person One']);

    selector.toggle('two');
    selector.toggle('three');
    assert.equal(selector.maxReached, true);
    assert.equal(selector.selectionSummary, 'Maximum of 3 preferred personnel selected.');

    selector.remove('two');
    assert.equal(selector.maxReached, false);
    assert.equal(selector.selectionSummary, '2 of 3 preferred personnel selected · 1 selection remaining');
});

test('search covers names and every skill while match presentation avoids repetition', () => {
    const selector = preferredPersonnelSelector({ selected: [] });
    selector.options = [{
        id: 'one',
        name: 'Marjun Portrias',
        designation: 'Technician',
        primary_skill: 'Carpentry / Woodworking',
        matching_skills: ['Masonry'],
        additional_skills: ['Air Conditioning'],
        skills: ['Carpentry / Woodworking', 'Masonry', 'Air Conditioning'],
    }];

    for (const query of ['Marjun', 'Carpentry', 'Masonry', 'Air Conditioning']) {
        selector.search = query;
        assert.equal(selector.filteredOptions.length, 1);
    }

    assert.equal(selector.primaryMatches(selector.options[0]), false);
    selector.options[0].matching_skills = ['Carpentry / Woodworking', 'Masonry'];
    assert.equal(selector.primaryMatches(selector.options[0]), true);
    assert.deepEqual(selector.otherMatchingSkills(selector.options[0]), ['Masonry']);
});

test('an unmapped work type returns a clear empty state without retaining stale selections', async () => {
    const selector = preferredPersonnelSelector({
        lookupUrl: '/categories/__CATEGORY__/personnel',
        selected: ['stale'],
    });
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => response([], false);

    try {
        await selector.load('unmapped');
    } finally {
        globalThis.fetch = originalFetch;
    }

    assert.equal(selector.mappingConfigured, false);
    assert.equal(selector.lookupState, 'unmapped');
    assert.deepEqual(selector.selected, []);
});

test('a failed lookup is distinguishable from a valid empty result', async () => {
    const selector = preferredPersonnelSelector({
        lookupUrl: '/categories/__CATEGORY__/personnel',
        selected: [],
    });
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => ({ ok: false });

    try {
        await selector.load('electrical');
    } finally {
        globalThis.fetch = originalFetch;
    }

    assert.equal(selector.lookupState, 'error');
    assert.equal(selector.errorMessage, 'Unable to load matching personnel. Please try again.');
});
