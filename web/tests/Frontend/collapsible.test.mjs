import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeCollapsibles } from '../../resources/js/collapsible.js';

class Summary {
    constructor() { this.attributes = {}; }
    setAttribute(name, value) { this.attributes[name] = value; }
}

class Details {
    constructor(open = false) {
        this.open = open;
        this.summary = new Summary();
        this.listeners = {};
    }

    querySelector(selector) {
        return selector === ':scope > [data-collapsible-summary]' ? this.summary : null;
    }

    addEventListener(event, handler) { this.listeners[event] = handler; }

    toggle(open) {
        this.open = open;
        this.listeners.toggle();
    }
}

test('aria-expanded follows the native details state through rapid toggles', () => {
    const collapsed = new Details(false);
    const expanded = new Details(true);
    const root = { querySelectorAll: () => [collapsed, expanded] };

    initializeCollapsibles(root);
    assert.equal(collapsed.summary.attributes['aria-expanded'], 'false');
    assert.equal(expanded.summary.attributes['aria-expanded'], 'true');

    collapsed.toggle(true);
    collapsed.toggle(false);
    collapsed.toggle(true);
    assert.equal(collapsed.summary.attributes['aria-expanded'], 'true');

    expanded.toggle(false);
    assert.equal(expanded.summary.attributes['aria-expanded'], 'false');
});
