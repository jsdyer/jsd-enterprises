'use strict';

const svgNamespace = 'http://www.w3.org/2000/svg';

function chartElement(name, attributes = {}, content = '') {
    const element = document.createElementNS(svgNamespace, name);
    for (const [attribute, value] of Object.entries(attributes)) {
        element.setAttribute(attribute, value);
    }
    if (content) element.textContent = content;
    return element;
}

function chartDateFormatter(options) {
    return new Intl.DateTimeFormat('en-CA', { ...options, timeZone: 'UTC' });
}

function chartScale(points) {
    const prices = points.map(point => point.close);
    const lowest = Math.min(...prices);
    const highest = Math.max(...prices);
    const padding = Math.max((highest - lowest) * 0.12, Math.abs(highest) * 0.002, 0.0001);
    const target = (highest - lowest + padding * 2) / 4;
    const magnitude = 10 ** Math.floor(Math.log10(target));
    const step = [1, 2, 2.5, 5, 10].find(size => size >= target / magnitude) * magnitude;
    const min = Math.max(0, Math.floor((lowest - padding) / step) * step);
    const max = Math.ceil((highest + padding) / step) * step;
    return { min, max, step };
}

function chartPoints(element) {
    let points;
    try {
        points = JSON.parse(element.dataset.points);
    } catch {
        return [];
    }
    if (!Array.isArray(points)) return [];

    return points.flatMap(point => {
        if (!point || typeof point.date !== 'string' || !Number.isFinite(point.close) || point.close < 0) return [];
        const timestamp = Date.parse(point.date + 'T00:00:00Z');
        return Number.isFinite(timestamp) ? [{ timestamp, close: point.close }] : [];
    });
}

function chartNumberFormatters(type, currency, step) {
    const isCurrency = (type === 'stock' || type === 'etf') && /^[A-Z]{3}$/.test(currency);
    const full = new Intl.NumberFormat('en-CA', {
        ...(isCurrency ? { style: 'currency', currency, currencyDisplay: 'narrowSymbol' } : {}),
        minimumFractionDigits: type === 'fx' ? 4 : 2,
        maximumFractionDigits: type === 'fx' ? 4 : 2,
    });
    const decimals = Math.min(6, Math.max(0, Math.ceil(-Math.log10(step)) + 1));
    const axis = new Intl.NumberFormat('en-CA', { maximumFractionDigits: decimals });
    return { full, axis };
}

function mountPriceChart(element, id) {
    const points = chartPoints(element);
    if (!points.length) return;

    const label = element.dataset.label || 'Price';
    const type = element.dataset.type || 'stock';
    const currency = element.dataset.currency || '';
    const height = Math.min(420, Math.max(160, Number(element.dataset.height) || 278));
    const monthDay = chartDateFormatter({ month: 'short', day: 'numeric' });
    const fullDate = chartDateFormatter({ month: 'short', day: 'numeric', year: 'numeric' });
    const scale = chartScale(points);
    const number = chartNumberFormatters(type, currency, scale.step);
    const last = points.length - 1;
    const gradientId = 'price-chart-fill-' + id;
    const accent = 'var(--chart-accent, #a68aff)';
    const gridColour = 'var(--chart-grid-color, #252d45)';
    const axisColour = 'var(--chart-label-color, #8d9ab5)';
    const tooltipBg = 'var(--chart-tooltip-bg, #171d33)';
    const tooltipText = 'var(--chart-tooltip-text, #f3efff)';
    let selected = last;
    let active = false;
    let view;

    element.setAttribute('role', last ? 'slider' : 'img');
    if (last) {
        element.tabIndex = 0;
        element.setAttribute('aria-label', label + ' daily closing prices');
        element.setAttribute('aria-valuemin', 1);
        element.setAttribute('aria-valuemax', points.length);
        element.setAttribute('aria-keyshortcuts', 'ArrowLeft ArrowRight Home End');
    } else {
        element.setAttribute('aria-label', label + ', ' + fullDate.format(points[0].timestamp)
            + ', ' + number.full.format(points[0].close));
    }

    function select(index, show) {
        selected = index;
        active = show;
        if (!view || !last) return;

        const { overlay, guide, marker, tooltip, day, value, coordinates, plot } = view;
        const point = points[index];
        const date = fullDate.format(point.timestamp);
        const price = number.full.format(point.close);
        element.setAttribute('aria-valuenow', index + 1);
        element.setAttribute('aria-valuetext', date + ', ' + price);
        overlay.setAttribute('visibility', show ? 'visible' : 'hidden');
        if (!show) return;

        const [x, y] = coordinates[index];
        guide.setAttribute('x1', x);
        guide.setAttribute('x2', x);
        marker.setAttribute('cx', x);
        marker.setAttribute('cy', y);
        day.textContent = date;
        value.textContent = price;

        const tooltipWidth = 122;
        const tooltipHeight = 52;
        const preferredX = x + 12 + tooltipWidth < plot.right ? x + 12 : x - tooltipWidth - 12;
        const boxX = Math.max(plot.left, Math.min(preferredX, plot.right - tooltipWidth));
        const preferredY = y - tooltipHeight - 12 < plot.top ? y + 12 : y - tooltipHeight - 12;
        const boxY = Math.max(plot.top, Math.min(preferredY, plot.bottom - tooltipHeight));
        tooltip.setAttribute('transform', 'translate(' + boxX + ' ' + boxY + ')');
    }

    function render() {
        const width = Math.max(220, Math.round(element.getBoundingClientRect().width));
        if (view?.width === width) return;

        const lastTick = number.axis.format(scale.max);
        const plot = { left: 8, right: width - Math.max(48, lastTick.length * 7.2 + 13), top: 10, bottom: height - 29 };
        const plotWidth = plot.right - plot.left;
        const plotHeight = plot.bottom - plot.top;
        const xAt = index => last ? plot.left + index * plotWidth / last : plot.left + plotWidth / 2;
        const yAt = price => plot.top + (scale.max - price) * plotHeight / (scale.max - scale.min);
        const coordinates = points.map((point, index) => [xAt(index), yAt(point.close)]);
        const line = coordinates.map(([x, y]) => x.toFixed(2) + ',' + y.toFixed(2)).join(' ');

        const svg = chartElement('svg', {
            class: 'chart-svg', width: '100%', height, viewBox: '0 0 ' + width + ' ' + height,
            'aria-hidden': 'true', focusable: 'false',
        });
        const defs = chartElement('defs');
        const gradient = chartElement('linearGradient', { id: gradientId, x1: '0%', x2: '0%', y1: '0%', y2: '100%' });
        gradient.append(
            chartElement('stop', { offset: '0%', 'stop-color': accent, 'stop-opacity': '0.28' }),
            chartElement('stop', { offset: '100%', 'stop-color': accent, 'stop-opacity': '0.02' }),
        );
        defs.append(gradient);
        svg.append(defs);

        const ticks = Math.round((scale.max - scale.min) / scale.step);
        for (let tick = 0; tick <= ticks; tick++) {
            const value = scale.min + tick * scale.step;
            const y = yAt(value);
            svg.append(
                chartElement('line', {
                    class: 'chart-grid', x1: plot.left, x2: plot.right, y1: y, y2: y,
                    stroke: gridColour, 'stroke-dasharray': '3 4',
                }),
                chartElement('text', {
                    class: 'chart-axis', x: plot.right + 10, y: y + 4, fill: axisColour, 'font-size': 11,
                }, number.axis.format(value)),
            );
        }

        const dateLabels = Math.min(points.length, width < 430 ? 3 : width < 630 ? 4 : 6);
        for (let tick = 0; tick < dateLabels; tick++) {
            const index = dateLabels === 1 ? 0 : Math.round(tick * last / (dateLabels - 1));
            const anchor = dateLabels === 1 || (tick > 0 && tick < dateLabels - 1) ? 'middle' : tick === 0 ? 'start' : 'end';
            svg.append(chartElement('text', {
                class: 'chart-axis', x: xAt(index), y: height - 8, 'text-anchor': anchor,
                fill: axisColour, 'font-size': 11,
            }, monthDay.format(points[index].timestamp)));
        }

        const [lastX, lastY] = coordinates[last];
        if (last) {
            svg.append(
                chartElement('polygon', {
                    points: plot.left + ',' + plot.bottom + ' ' + line + ' ' + plot.right + ',' + plot.bottom,
                    fill: 'url(#' + gradientId + ')',
                }),
                chartElement('polyline', {
                    class: 'chart-line', points: line, fill: 'none', stroke: accent, 'stroke-width': '2.3',
                    'stroke-linecap': 'round', 'stroke-linejoin': 'round',
                }),
            );
        }
        svg.append(chartElement('circle', { cx: lastX, cy: lastY, r: 3.6, fill: accent }));

        const overlay = chartElement('g', { visibility: 'hidden' });
        const guide = chartElement('line', {
            class: 'chart-guide', y1: plot.top, y2: plot.bottom, stroke: accent, 'stroke-dasharray': '3 4',
            'stroke-opacity': '0.72',
        });
        const marker = chartElement('circle', { class: 'chart-dot', r: 5, fill: accent, stroke: tooltipBg, 'stroke-width': 2 });
        const tooltip = chartElement('g', { class: 'chart-tooltip' });
        const day = chartElement('text', { x: 10, y: 19, fill: axisColour, 'font-size': 11 });
        const value = chartElement('text', { x: 10, y: 39, fill: tooltipText, 'font-size': 14, 'font-weight': 600 });
        tooltip.append(chartElement('rect', {
            width: 122, height: 52, rx: 7, fill: tooltipBg, stroke: gridColour,
        }), day, value);
        overlay.append(guide, marker, tooltip);
        svg.append(overlay);
        element.replaceChildren(svg);

        view = { width, svg, plot, coordinates, overlay, guide, marker, tooltip, day, value };
        select(selected, active);
    }

    render();
    if ('ResizeObserver' in window) {
        new ResizeObserver(render).observe(element);
    } else {
        window.addEventListener('resize', render);
    }
    if (!last) return;

    function move(event) {
        const bounds = view.svg.getBoundingClientRect();
        const x = (event.clientX - bounds.left) * view.width / bounds.width;
        const relative = (x - view.plot.left) / (view.plot.right - view.plot.left);
        select(Math.min(last, Math.max(0, Math.round(relative * last))), true);
    }

    element.addEventListener('pointermove', move);
    element.addEventListener('pointerdown', event => {
        element.focus({ preventScroll: true });
        move(event);
    });
    element.addEventListener('pointerleave', () => select(selected, document.activeElement === element));
    element.addEventListener('focus', () => select(selected, true));
    element.addEventListener('blur', () => select(selected, false));
    element.addEventListener('keydown', event => {
        const moves = {
            ArrowLeft: Math.max(0, selected - 1),
            ArrowRight: Math.min(last, selected + 1),
            ArrowDown: Math.max(0, selected - 1),
            ArrowUp: Math.min(last, selected + 1),
            Home: 0,
            End: last,
        };
        if (!Object.hasOwn(moves, event.key)) return;
        event.preventDefault();
        select(moves[event.key], true);
    });
}

document.querySelectorAll('[data-price-chart]').forEach(mountPriceChart);

function mountSymbolSearch(form, id) {
    const input = form.querySelector('input[name="symbol"]');
    const results = form.querySelector('[data-symbol-results]');
    if (!input || !results) return;

    let symbols;
    try {
        symbols = JSON.parse(form.dataset.symbols);
    } catch {
        return;
    }
    if (!Array.isArray(symbols)) return;
    symbols = symbols.filter(entry => typeof entry?.symbol === 'string' && typeof entry?.name === 'string');
    if (!symbols.length) return;

    const action = new URL(form.action);
    action.hash = 'chart';
    form.action = action.href;
    results.id ||= 'symbol-search-results-' + id;
    results.setAttribute('role', 'listbox');
    results.setAttribute('aria-label', 'Matching securities');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-controls', results.id);
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('autocomplete', 'off');
    let matches = [];
    let options = [];
    let selected = -1;

    function close() {
        results.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        selected = -1;
    }

    function choose(index) {
        input.value = matches[index].symbol;
        close();
        form.requestSubmit();
    }

    function select(index) {
        selected = index;
        options.forEach((option, optionIndex) => {
            option.setAttribute('aria-selected', String(index === optionIndex));
        });
        input.setAttribute('aria-activedescendant', options[index].id);
    }

    function show() {
        const query = input.value.trim().toLowerCase();
        const ticker = query.replace(/^tsxv?:\s*/, '').replace(/\.to$/, '');
        matches = symbols.map(entry => {
            const code = entry.symbol.toLowerCase();
            const name = entry.name.toLowerCase();
            if (!query || code === ticker) return { entry, rank: 0 };
            if (code.startsWith(ticker)) return { entry, rank: 1 };
            if (name.startsWith(query)) return { entry, rank: 2 };
            if (code.includes(ticker) || name.includes(query)) return { entry, rank: 3 };
            return { entry, rank: 4 };
        }).filter(match => match.rank < 4)
            .sort((first, second) => first.rank - second.rank)
            .slice(0, 6)
            .map(match => match.entry);

        close();
        options = matches.map((entry, index) => {
            const option = document.createElement('button');
            option.id = results.id + '-option-' + index;
            option.className = 'symbol-result';
            option.type = 'button';
            option.tabIndex = -1;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.addEventListener('click', () => choose(index));

            const code = document.createElement('span');
            code.className = 'symbol-result-code';
            code.textContent = entry.symbol;
            const name = document.createElement('span');
            name.className = 'symbol-result-name';
            name.textContent = entry.name;
            option.append(code, name);
            return option;
        });
        results.replaceChildren(...options);
        if (!options.length) return;

        results.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    input.addEventListener('input', show);
    input.addEventListener('focus', show);
    form.addEventListener('focusout', event => {
        if (!form.contains(event.relatedTarget)) close();
    });
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !results.hidden) {
            event.preventDefault();
            close();
        } else if (event.key === 'Enter' && !results.hidden && (selected >= 0 || options.length === 1)) {
            event.preventDefault();
            choose(Math.max(0, selected));
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (results.hidden) show();
            if (!options.length) return;
            event.preventDefault();
            const increment = event.key === 'ArrowDown' ? 1 : -1;
            const initial = increment === 1 ? -1 : 0;
            select(((selected < 0 ? initial : selected) + increment + options.length) % options.length);
        }
    });
}

document.querySelectorAll('[data-symbol-search]').forEach(mountSymbolSearch);
