// Graphique d'une métrique d'une target (SCRUM-44 : temps de réponse ; SCRUM-72 : toutes les séries du type,
// CPU, mémoire, disque, connexions…), alimenté par l'API interne GET /targets/{id}/metrics/{metric}?period=1h|24h|7d
import {
    Chart,
    Filler,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, Filler, Tooltip);

// Couleurs du thème sombre (resources/scss/app.scss)
const COLORS = {
    line: '#5b8def',
    warning: '#f2b84b',
    critical: '#f0545c',
    grid: '#2f3250',
    text: '#9396b4',
    surface: '#1e2036',
};

const timeFormats = {
    '1h': { hour: '2-digit', minute: '2-digit' },
    '24h': { hour: '2-digit', minute: '2-digit' },
    '7d': { weekday: 'short', day: '2-digit', hour: '2-digit' },
};

// Valeur dans l'unité de la série : « 512 ms », « 42.5 % », « 300.0 MB », « 12 »
function format(value, unit) {
    switch (unit) {
        case 'ms':
            return `${Math.round(value).toLocaleString('en-US')} ms`;
        case '%':
        case 'MB':
            return `${value.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} ${unit}`;
        default:
            return `${Math.round(value).toLocaleString('en-US')}${unit ? ` ${unit}` : ''}`;
    }
}

// Ligne verticale qui suit le pointeur (crosshair)
const crosshair = {
    id: 'crosshair',
    afterDatasetsDraw(chart) {
        const active = chart.tooltip?.getActiveElements();
        if (!active?.length) return;
        const { ctx, chartArea } = chart;
        const x = active[0].element.x;
        ctx.save();
        ctx.strokeStyle = COLORS.text;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, chartArea.top);
        ctx.lineTo(x, chartArea.bottom);
        ctx.stroke();
        ctx.restore();
    },
};

// Étiquette directe au bout de chaque ligne de seuil (identité sans dépendre de la couleur)
const thresholdLabels = {
    id: 'thresholdLabels',
    afterDatasetsDraw(chart) {
        const { ctx, chartArea, scales } = chart;
        ctx.save();
        ctx.font = '600 11px "Inter Variable", system-ui, sans-serif';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'bottom';
        chart.data.datasets.forEach((dataset) => {
            if (!dataset.threshold) return;
            const y = scales.y.getPixelForValue(dataset.threshold.value);
            if (y < chartArea.top || y > chartArea.bottom) return;
            ctx.fillStyle = COLORS.text;
            ctx.fillText(`${dataset.threshold.label} ${format(dataset.threshold.value, dataset.threshold.unit)}`, chartArea.right - 4, y - 3);
        });
        ctx.restore();
    },
};

function thresholdDataset(label, value, unit, color, from, to) {
    return {
        label,
        threshold: { label, value, unit },
        data: [{ x: from, y: value }, { x: to, y: value }],
        borderColor: color,
        borderWidth: 1.5,
        borderDash: [6, 4],
        pointRadius: 0,
        pointHitRadius: 0,
        fill: false,
    };
}

// Coupe la courbe là où il manque des mesures (Zabbix ou Docker arrêté) au lieu de relier les deux bords
function withGaps(points) {
    if (points.length < 3) return points.map(([x, y]) => ({ x, y }));
    const steps = points.slice(1).map(([x], i) => x - points[i][0]).sort((a, b) => a - b);
    const usual = steps[Math.floor(steps.length / 2)];
    const series = [];
    points.forEach(([x, y], i) => {
        if (i > 0 && x - points[i - 1][0] > usual * 5) series.push({ x: points[i - 1][0] + usual, y: null });
        series.push({ x, y });
    });
    return series;
}

function render(canvas, data, chart) {
    const { from, to, points, thresholds, period, unit, label } = data;
    const series = withGaps(points);
    // Seuils de la série, chacun facultatif (base de données : aucun en ms ; conteneur : warning seulement)
    const lines = [
        ['Critical', thresholds.critical, COLORS.critical],
        ['Warning', thresholds.warning, COLORS.warning],
    ].filter(([, value]) => value !== null && value !== undefined);
    // L'axe montre toujours les seuils, même si toutes les mesures sont bien plus basses
    const yMax = Math.max(1, ...lines.map(([, value]) => value * 1.15), ...points.map(([, y]) => y * 1.1));

    const datasets = [
        {
            label,
            data: series,
            borderColor: COLORS.line,
            backgroundColor: 'rgba(91, 141, 239, 0.12)',
            borderWidth: 2,
            fill: 'origin',
            tension: 0.25,
            spanGaps: false,
            pointRadius: points.length === 1 ? 4 : 0,
            pointHoverRadius: 5,
            pointHoverBorderColor: COLORS.surface,
            pointHoverBorderWidth: 2,
            pointBackgroundColor: COLORS.line,
        },
        ...lines.map(([name, value, color]) => thresholdDataset(name, value, unit, color, from, to)),
    ];

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        parsing: false,
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        scales: {
            x: {
                type: 'linear',
                min: from,
                max: to,
                grid: { color: COLORS.grid, drawTicks: false },
                border: { color: COLORS.grid },
                ticks: {
                    color: COLORS.text,
                    padding: 8,
                    maxTicksLimit: 7,
                    callback: (value) => new Date(value).toLocaleString(undefined, timeFormats[period]),
                },
            },
            y: {
                min: 0,
                suggestedMax: unit === '%' ? Math.min(100, yMax) : yMax,
                grid: { color: COLORS.grid, drawTicks: false },
                border: { display: false },
                ticks: { color: COLORS.text, padding: 8, maxTicksLimit: 6, callback: (value) => `${value}${unit ? ` ${unit}` : ''}` },
            },
        },
        plugins: {
            legend: { display: false },
            tooltip: {
                // Seule la série mesurée a une infobulle ; les seuils sont étiquetés sur le graphique
                filter: (item) => item.datasetIndex === 0,
                displayColors: false,
                backgroundColor: COLORS.surface,
                borderColor: COLORS.grid,
                borderWidth: 1,
                titleColor: '#ffffff',
                bodyColor: '#e6e7f2',
                padding: 10,
                callbacks: {
                    title: (items) => new Date(items[0].parsed.x).toLocaleString(undefined, {
                        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
                    }),
                    label: (item) => format(item.parsed.y, unit),
                },
            },
        },
    };

    if (chart) {
        chart.data.datasets = datasets;
        chart.options = options;
        chart.update();
        return chart;
    }

    return new Chart(canvas, { type: 'line', data: { datasets }, options, plugins: [crosshair, thresholdLabels] });
}

function initMetricChart(root) {
    const canvas = root.querySelector('canvas');
    const frame = root.querySelector('[data-chart-frame]');
    const message = root.querySelector('[data-chart-message]');
    const title = root.querySelector('[data-chart-title]');
    const periodButtons = root.querySelectorAll('[data-period]');
    const metricButtons = root.querySelectorAll('[data-metric-url]');
    const stats = {
        avg: root.querySelector('[data-stat="avg"]'),
        min: root.querySelector('[data-stat="min"]'),
        max: root.querySelector('[data-stat="max"]'),
        count: root.querySelector('[data-stat="count"]'),
    };
    let chart = null;
    let url = root.dataset.url;
    let period = root.dataset.period || '1h';

    function showMessage(text) {
        message.textContent = text;
        message.classList.toggle('d-none', !text);
    }

    function select(buttons, isSelected) {
        buttons.forEach((b) => {
            b.classList.toggle('active', isSelected(b));
            b.setAttribute('aria-pressed', isSelected(b));
        });
    }

    async function load() {
        select(periodButtons, (b) => b.dataset.period === period);
        select(metricButtons, (b) => b.dataset.metricUrl === url);
        // Rechargement : l'ancien graphique reste affiché, atténué (pas de saut de mise en page)
        frame.style.opacity = '0.45';

        try {
            const response = await window.axios.get(url, { params: { period } });
            const data = response.data;
            const show = (value) => (value !== null ? format(value, data.unit) : '—');
            chart = render(canvas, data, chart);

            if (title) title.textContent = data.label;
            stats.avg.textContent = show(data.stats.avg);
            stats.min.textContent = show(data.stats.min);
            stats.max.textContent = show(data.stats.max);
            stats.count.textContent = data.stats.count.toLocaleString('en-US');
            canvas.setAttribute('aria-label', data.stats.count
                ? `${data.label} over the last ${period}: average ${show(data.stats.avg)}, minimum ${show(data.stats.min)}, maximum ${show(data.stats.max)}.`
                : `No ${data.label.toLowerCase()} measured over the last ${period}.`);

            showMessage(data.points.length ? '' : 'No measure over this period yet.');
        } catch (error) {
            // Message générique renvoyé par l'API (Zabbix indisponible), jamais d'erreur technique
            showMessage(error.response?.data?.message ?? 'Metrics are temporarily unavailable.');
        } finally {
            frame.style.opacity = '';
        }
    }

    periodButtons.forEach((button) => button.addEventListener('click', () => {
        period = button.dataset.period;
        load();
    }));
    metricButtons.forEach((button) => button.addEventListener('click', () => {
        url = button.dataset.metricUrl;
        load();
    }));
    load();
}

document.querySelectorAll('[data-metric-chart]').forEach(initMetricChart);
