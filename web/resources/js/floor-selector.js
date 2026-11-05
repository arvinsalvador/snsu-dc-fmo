/**
 * Alpine component for a Building → Floor dependent select.
 * Reuses the existing floors lookup endpoint; the server re-validates the pair on submit.
 */
export function floorSelector({ url, building = '', floor = '', floors = [] }) {
    return {
        building: building ? String(building) : '',
        floor: floor ? String(floor) : '',
        options: building ? floors : [],
        loading: false,
        failed: false,
        requestId: 0,

        get disabled() {
            return !this.building || this.loading || this.failed;
        },

        get placeholder() {
            if (!this.building) return 'Select a building first';
            if (this.loading) return 'Loading floors...';
            if (this.failed) return 'Unable to load floors. Please try again.';
            return 'No floor / building-level';
        },

        get noFloors() {
            return Boolean(this.building) && !this.loading && !this.failed && this.options.length === 0;
        },

        async load() {
            const requestId = ++this.requestId;
            this.floor = '';
            this.options = [];
            this.failed = false;
            if (!this.building) {
                this.loading = false;
                return;
            }
            this.loading = true;
            try {
                const target = new URL(url, window.location.origin);
                target.searchParams.set('building_id', this.building);
                target.searchParams.set('include_inactive', '1');
                const response = await fetch(target, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error(`Floor lookup failed (${response.status})`);
                const result = await response.json();
                if (!Array.isArray(result.data)) throw new Error('Invalid floor lookup response');
                if (requestId !== this.requestId) return;
                this.options = result.data.map(item => ({ id: String(item.id), name: item.name }));
            } catch (error) {
                if (requestId !== this.requestId) return;
                this.failed = true;
                console.error(error);
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },
    };
}
