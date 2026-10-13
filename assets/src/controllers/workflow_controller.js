import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['graph', 'place', 'status', 'search', 'empty'];
    static values = { digraph: String, details: Object };

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
            edge.dataset.transition = edge.id.match(/^wf-transition-(\d+)-/)?.[1] ?? '';
        });
        this.prepareHovercard(target, options);
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
            this.hideHovercard();
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
        target.addEventListener('wheel', event => this.wheelZoom(event), { ...options, passive: false });
        this.clear();
    }

    prepareHovercard(target, options) {
        // Graphviz emits each tooltip as xlink:title, which browsers show late or not at all.
        // Keep it as the fallback text and drop native titles so only the card appears.
        [...this.nodes, ...this.edges].forEach(group => {
            const link = group.querySelector('a');
            group.dataset.tip = link?.getAttributeNS('http://www.w3.org/1999/xlink', 'title') ?? '';
            link?.removeAttributeNS('http://www.w3.org/1999/xlink', 'title');
            group.querySelectorAll('title').forEach(title => title.remove());
            const show = event => this.showHovercard(group, event);
            group.addEventListener('pointerenter', show, options);
            group.addEventListener('pointermove', show, options);
            group.addEventListener('pointerleave', () => this.hideHovercard(), options);
            group.addEventListener('focus', show, options);
            group.addEventListener('blur', () => this.hideHovercard(), options);
        });
        // The graph's own <title> would otherwise show "workflow" over empty canvas.
        this.svg.querySelectorAll(':scope > g > title').forEach(title => title.remove());
        this.hovercard?.remove();
        this.hovercard = document.createElement('div');
        this.hovercard.className = 'wf-hovercard';
        this.hovercard.setAttribute('role', 'tooltip');
        this.hovercard.hidden = true;
        target.after(this.hovercard);
    }

    hovercardContent(group) {
        const places = this.detailsValue.places ?? {};
        const transitions = this.detailsValue.transitions ?? [];
        if (group.classList.contains('node')) {
            const place = places[group.dataset.place];
            return place ? { title: place.label, code: group.dataset.place, description: place.description } : null;
        }
        const transition = transitions[group.dataset.transition];
        if (!transition) return null;
        const from = places[group.dataset.from]?.label ?? group.dataset.from;
        const to = places[group.dataset.to]?.label ?? group.dataset.to;
        return {
            title: transition.label,
            code: `${transition.name} · ${from} → ${to}${transition.async ? ' · async' : ''}`,
            description: transition.description,
            guardLabel: transition.guardLabel,
            guard: transition.guard,
        };
    }

    showHovercard(group, event) {
        if (!this.hovercard || this.drag && this.dragged) return;
        const content = this.hovercardContent(group) ?? (group.dataset.tip ? { description: group.dataset.tip } : null);
        if (!content) return this.hideHovercard();
        const element = (tag, className, text) => {
            const node = document.createElement(tag);
            if (className) node.className = className;
            node.textContent = text;
            return node;
        };
        const parts = [];
        if (content.title) parts.push(element('strong', '', content.title));
        if (content.code) parts.push(element('span', 'wf-code', content.code));
        if (content.description) parts.push(element('p', '', content.description));
        if (content.guard) {
            const guard = element('div', 'wf-guard', '');
            guard.append(element('span', 'wf-eyebrow', content.guardLabel || 'Guard'), element('code', '', content.guard));
            parts.push(guard);
        }
        this.hovercard.replaceChildren(...parts);
        this.hovercard.hidden = false;

        const container = this.hovercard.offsetParent ?? this.element;
        const bounds = container.getBoundingClientRect();
        const anchor = event?.clientX !== undefined
            ? { x: event.clientX, y: event.clientY }
            : (({ left, right, bottom }) => ({ x: (left + right) / 2, y: bottom }))(group.getBoundingClientRect());
        const card = this.hovercard.getBoundingClientRect();
        let left = anchor.x - bounds.left + 14;
        let top = anchor.y - bounds.top + 14;
        if (left + card.width > bounds.width - 8) left = anchor.x - bounds.left - card.width - 14;
        if (top + card.height > bounds.height - 8) top = anchor.y - bounds.top - card.height - 14;
        this.hovercard.style.left = `${Math.max(8, left)}px`;
        this.hovercard.style.top = `${Math.max(8, top)}px`;
    }

    hideHovercard() {
        if (this.hovercard) this.hovercard.hidden = true;
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
        if (this.hasStatusTarget) this.statusTarget.textContent = 'Select a state to trace its connections · Drag to pan · Option/Alt + scroll or pinch to zoom';
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
    wheelZoom(event) {
        // Trackpad pinch arrives as Ctrl+wheel. Option/Alt+scroll leaves ordinary page scrolling available.
        if (!event.ctrlKey && !event.altKey) return;
        event.preventDefault();
        const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? this.svg.clientHeight : 1;
        const delta = Math.max(-100, Math.min(100, event.deltaY * unit));
        const matrix = this.svg.getScreenCTM();
        if (!matrix) return;
        const anchor = new DOMPoint(event.clientX, event.clientY).matrixTransform(matrix.inverse());
        this.zoom(Math.exp(delta * 0.01), anchor);
    }
    zoom(factor, anchor = null) {
        if (!this.svg) return;
        const [x, y, width, height] = this.viewBox;
        const nextWidth = width * factor;
        if (nextWidth < this.initialViewBox[2] / 5 || nextWidth > this.initialViewBox[2] * 2) return;
        const focus = anchor ?? { x: x + width / 2, y: y + height / 2 };
        this.viewBox = [focus.x + (x - focus.x) * factor, focus.y + (y - focus.y) * factor, nextWidth, height * factor];
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
        this.hovercard?.remove();
        this.hovercard = null;
        this.renderer?.destroy();
        this.renderer = null;
    }
}
