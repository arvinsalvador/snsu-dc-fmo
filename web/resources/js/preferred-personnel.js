export function preferredPersonnelSelector(config) {
    return {
        open: false,
        loading: false,
        categoryId: String(config.categoryId || ''),
        lookupUrl: config.lookupUrl,
        maxSelections: Number(config.maxSelections || 3),
        selected: (config.selected || []).map(String),
        options: [],
        search: '',
        mappingConfigured: null,
        lookupState: 'idle',
        removedMessage: '',
        errorMessage: '',
        requestSequence: 0,

        init() {
            const category = this.$root.closest('form')?.querySelector('[name="work_order_category_id"]');
            if (!category) return;
            this.categoryId = category.value;
            category.addEventListener('change', event => this.load(String(event.target.value)));
            if (this.categoryId) this.load(this.categoryId);
        },

        get selectedPeople() {
            return this.selected.map(id => this.options.find(person => person.id === id)).filter(Boolean);
        },

        get availabilitySummary() {
            if (this.lookupState !== 'ready') return '';

            const selectionState = this.selectedPeople.length ? '' : ' · No preference selected';

            return `${this.options.length} matching FMO personnel${selectionState}`;
        },

        get maxReached() {
            return this.selected.length >= this.maxSelections;
        },

        get selectionSummary() {
            if (!this.selected.length) return '';
            if (this.maxReached) return `Maximum of ${this.maxSelections} preferred personnel selected.`;

            const remaining = this.maxSelections - this.selected.length;

            return `${this.selected.length} of ${this.maxSelections} preferred personnel selected · ${remaining} ${remaining === 1 ? 'selection' : 'selections'} remaining`;
        },

        get filteredOptions() {
            const query = this.search.trim().toLocaleLowerCase();
            if (!query) return this.options;
            return this.options.filter(person => [
                person.name,
                person.designation,
                person.primary_skill,
                ...(person.skills || []),
                ...(person.matching_skills || []),
            ].filter(Boolean).join(' ').toLocaleLowerCase().includes(query));
        },

        async load(categoryId) {
            this.categoryId = categoryId;
            this.open = false;
            this.search = '';
            this.errorMessage = '';
            this.removedMessage = '';
            const sequence = ++this.requestSequence;
            if (!categoryId) {
                this.options = [];
                this.selected = [];
                this.mappingConfigured = null;
                this.lookupState = 'idle';
                return;
            }

            this.loading = true;
            try {
                const response = await fetch(this.lookupUrl.replace('__CATEGORY__', encodeURIComponent(categoryId)), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error('Unable to load matching personnel.');
                const payload = await response.json();
                if (sequence !== this.requestSequence) return;
                this.options = (payload.data || []).map(person => ({ ...person, id: String(person.id) }));
                this.mappingConfigured = Boolean(payload.mapping_configured);
                this.lookupState = payload.state || (this.mappingConfigured ? (this.options.length ? 'ready' : 'no_matches') : 'unmapped');
                this.maxSelections = Number(payload.max_selections || this.maxSelections);
                const eligible = new Set(this.options.map(person => person.id));
                const previousCount = this.selected.length;
                this.selected = this.selected.filter(id => eligible.has(id)).slice(0, this.maxSelections);
                if (this.selected.length < previousCount) {
                    this.removedMessage = 'Some preferred personnel were removed because their skills do not match the selected Work Type.';
                }
            } catch (error) {
                if (sequence !== this.requestSequence) return;
                this.options = [];
                this.mappingConfigured = null;
                this.lookupState = 'error';
                this.errorMessage = 'Unable to load matching personnel. Please try again.';
            } finally {
                if (sequence === this.requestSequence) this.loading = false;
            }
        },

        isSelected(id) {
            return this.selected.includes(String(id));
        },

        primaryMatches(person) {
            return Boolean(person.primary_skill && (person.matching_skills || []).includes(person.primary_skill));
        },

        otherMatchingSkills(person) {
            return (person.matching_skills || []).filter(skill => skill !== person.primary_skill);
        },

        toggle(id) {
            id = String(id);
            this.errorMessage = '';
            if (this.isSelected(id)) {
                this.selected = this.selected.filter(selectedId => selectedId !== id);
                return;
            }
            if (this.selected.length >= this.maxSelections) {
                this.errorMessage = `You may select up to ${this.maxSelections} preferred personnel.`;
                return;
            }
            this.selected.push(id);
        },

        remove(id) {
            this.selected = this.selected.filter(selectedId => selectedId !== String(id));
        },
    };
}
