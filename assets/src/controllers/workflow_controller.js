import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['graph', 'place', 'status', 'search', 'empty'];
    static values = { digraph: String };

    async connect() {
        const run = this.run = {};
        const target = this.hasGraphTarget ? this.graphTarget : this.element;
        const fail = error => {
            if (this.run !== run) return;
            const message = document.createElement('p');
            message.setAttribute('role', 'alert');
            message.className = 'text-danger p-3';
            message.textContent = 'The workflow diagram could not be loaded. Places and transitions are listed below or beside it.';
            target.replaceChildren(message);
            console.error('Unable to render workflow diagram:', error);
        };

        try {
            // Catch missing dependencies too, so a broken import map never leaves an empty card.
            const { graphviz } = await import('d3-graphviz');
            if (this.run !== run) return;
            target.replaceChildren();
            this.renderer = graphviz(target, { useWorker: false, zoom: false });
            this.renderer.onerror(fail).renderDot(this.digraphValue, () => {
                if (this.run !== run) return;
                const svg = target.querySelector('svg');
                if (svg) {
                    svg.removeAttribute('width');
                    svg.removeAttribute('height');
                    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
                }
                this.prepareGraph(target);
                // Promote Graphviz link tooltips to native SVG titles.
                target.querySelectorAll('g.node, g.edge').forEach(group => {
                    const link = group.querySelector('a');
                    const tip = link?.getAttributeNS('http://www.w3.org/1999/xlink', 'title');
                    if (!tip) return;
                    let title = group.querySelector(':scope > title');
                    if (!title) {
                        title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
                        group.prepend(title);
                    }
                    title.textContent = tip;
                });
            });
        } catch (error) {
            fail(error);
        }
    }

    prepareGraph(target) {
        this.svg = target.querySelector('svg');
        if (!this.svg) return;
        this.initialViewBox = this.svg.getAttribute('viewBox').split(/\s+/).map(Number);
        this.viewBox = [...this.initialViewBox];
        this.nodes = [...target.querySelectorAll('g.node')];
        this.edges = [...target.querySelectorAll('g.edge')];
        this.events?.abort();
        this.events = new AbortController();
        const options = { signal: this.events.signal };
        this.nodes.forEach(node => {
            const name = node.querySelector(':scope > title')?.textContent.replace(/^place_/, '');
            node.dataset.place = name;
            node.setAttribute('tabindex', '0');
            node.setAttribute('role', 'button');
            node.setAttribute('aria-label', `Explore ${node.querySelector('text')?.textContent ?? name}`);
            node.setAttribute('aria-pressed', 'false');
            node.addEventListener('click', () => { if (!this.dragged) this.focusPlace(name); }, options);
            node.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    this.focusPlace(name);
                }
            }, options);
        });
        this.edges.forEach(edge => {
            const endpoints = edge.querySelector(':scope > title')?.textContent.split('->') ?? [];
            edge.dataset.from = endpoints[0]?.replace(/^place_/, '') ?? '';
            edge.dataset.to = endpoints[1]?.replace(/^place_/, '') ?? '';
        });
        target.addEventListener('pointerdown', event => {
            if (event.button !== 0) return;
            this.dragged = false;
            this.drag = { x: event.clientX, y: event.clientY, box: [...this.viewBox] };
        }, options);
        target.addEventListener('pointermove', event => {
            if (!this.drag) return;
            const dx = event.clientX - this.drag.x;
            const dy = event.clientY - this.drag.y;
            if (Math.hypot(dx, dy) < 4 && !this.dragged) return;
            this.dragged = true;
            target.setPointerCapture(event.pointerId);
            const rect = this.svg.getBoundingClientRect();
            const scale = Math.max(this.viewBox[2] / rect.width, this.viewBox[3] / rect.height);
            this.viewBox[0] = this.drag.box[0] - dx * scale;
            this.viewBox[1] = this.drag.box[1] - dy * scale;
            this.applyViewBox();
        }, options);
        for (const name of ['pointerup', 'pointercancel', 'pointerleave']) {
            target.addEventListener(name, () => { this.drag = null; }, options);
        }
        this.clear();
    }

    select(event) { this.focusPlace(event.currentTarget.dataset.place); }

    focusPlace(name) {
        const adjacent = new Set([name]);
        this.edges?.forEach(edge => {
            const connected = edge.dataset.from === name || edge.dataset.to === name;
            edge.classList.toggle('wf-dim', !connected);
            edge.classList.toggle('wf-connected', connected);
            if (connected) { adjacent.add(edge.dataset.from); adjacent.add(edge.dataset.to); }
        });
        this.nodes?.forEach(node => {
            const selected = node.dataset.place === name;
            node.classList.toggle('wf-selected', selected);
            node.classList.toggle('wf-dim', !adjacent.has(node.dataset.place));
            node.setAttribute('aria-pressed', String(selected));
        });
        this.placeTargets.forEach(row => {
            const selected = row.dataset.place === name;
            row.classList.toggle('wf-active', selected);
            row.querySelector('button').setAttribute('aria-pressed', String(selected));
            row.querySelector('.wf-details').hidden = !selected;
            if (selected) {
                // A graph selection should remain discoverable even with a previous search.
                if (this.hasSearchTarget) this.searchTarget.value = '';
            }
        });
        this.search();
        const selectedRow = this.placeTargets.find(row => row.dataset.place === name);
        if (selectedRow) selectedRow.parentElement.scrollTop = selectedRow.offsetTop - selectedRow.parentElement.offsetTop;
        if (this.hasStatusTarget) this.statusTarget.textContent = `${name.replaceAll('_', ' ')} · incoming and outgoing paths highlighted`;
    }

    clear() {
        this.nodes?.forEach(node => {
            node.classList.remove('wf-selected', 'wf-dim');
            node.setAttribute('aria-pressed', 'false');
        });
        this.edges?.forEach(edge => edge.classList.remove('wf-dim', 'wf-connected'));
        this.placeTargets.forEach(row => {
            row.classList.remove('wf-active');
            row.querySelector('button').setAttribute('aria-pressed', 'false');
            row.querySelector('.wf-details').hidden = true;
        });
        if (this.hasSearchTarget) this.searchTarget.value = '';
        this.search();
        if (this.hasStatusTarget) this.statusTarget.textContent = 'Select a state to trace its connections · Drag to pan';
    }

    search() {
        const query = this.hasSearchTarget ? this.searchTarget.value.trim().toLowerCase() : '';
        let count = 0;
        this.placeTargets.forEach(row => {
            row.hidden = !row.querySelector('button').textContent.toLowerCase().includes(query)
                && !row.dataset.place.toLowerCase().includes(query);
            if (!row.hidden) count++;
        });
        if (this.hasEmptyTarget) this.emptyTarget.hidden = count > 0;
    }

    zoomIn() { this.zoom(0.8); }
    zoomOut() { this.zoom(1.25); }
    zoom(factor) {
        if (!this.svg) return;
        const [x, y, width, height] = this.viewBox;
        const nextWidth = width * factor;
        if (nextWidth < this.initialViewBox[2] / 5 || nextWidth > this.initialViewBox[2] * 2) return;
        this.viewBox = [x + (width - nextWidth) / 2, y + (height - height * factor) / 2, nextWidth, height * factor];
        this.applyViewBox();
    }
    fit() {
        if (!this.svg) return;
        this.viewBox = [...this.initialViewBox];
        this.applyViewBox();
    }
    applyViewBox() { this.svg.setAttribute('viewBox', this.viewBox.join(' ')); }

    disconnect() {
        this.run = null;
        this.events?.abort();
        this.drag = null;
        this.svg = null;
        this.renderer?.destroy();
        this.renderer = null;
    }
}
