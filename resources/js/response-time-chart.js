// Graphique du temps de réponse d'une target (SCRUM-44), alimenté par l'API interne
// GET /targets/{id}/metrics/response-time?period=1h|24h|7d
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

const formatMs = (ms) => `${Math.round(ms).toLocaleString('en-US')} ms`;

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
            ctx.fillText(`${dataset.threshold.label} ${formatMs(dataset.threshold.value)}`, chartArea.right - 4, y - 3);
        });
        ctx.restore();
    },
};

function thresholdDataset(label, value, color, from, to) {
    return {
        label,
        threshold: { label, value },
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
    const { from, to, points, thresholds, period } = data;
    const series = withGaps(points);
    // L'axe montre toujours les seuils, même si toutes les mesures sont bien plus basses
    const yMax = Math.max(thresholds.critical * 1.15, ...points.map(([, y]) => y * 1.1));

    const datasets = [
        {
            label: 'Response time',
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
        thresholdDataset('Critical', thresholds.critical, COLORS.critical, from, to),
        thresholdDataset('Warning', thresholds.warning, COLORS.warning, from, to),
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
                suggestedMax: yMax,
                grid: { color: COLORS.grid, drawTicks: false },
                border: { display: false },
                ticks: { color: COLORS.text, padding: 8, maxTicksLimit: 6, callback: (value) => `${value} ms` },
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
                    label: (item) => formatMs(item.parsed.y),
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

function initResponseTimeChart(root) {
    const canvas = root.querySelector('canvas');
    const frame = root.querySelector('[data-chart-frame]');
    const message = root.querySelector('[data-chart-message]');
    const buttons = root.querySelectorAll('[data-period]');
    const stats = {
        avg: root.querySelector('[data-stat="avg"]'),
        min: root.querySelector('[data-stat="min"]'),
        max: root.querySelector('[data-stat="max"]'),
        count: root.querySelector('[data-stat="count"]'),
    };
    let chart = null;

    function showMessage(text) {
        message.textContent = text;
        message.classList.toggle('d-none', !text);
    }

    async function load(period) {
        buttons.forEach((b) => {
            const selected = b.dataset.period === period;
            b.classList.toggle('active', selected);
            b.setAttribute('aria-pressed', selected);
        });
        // Rechargement : l'ancien graphique reste affiché, atténué (pas de saut de mise en page)
        frame.style.opacity = '0.45';

        try {
            const response = await window.axios.get(root.dataset.url, { params: { period } });
            const data = response.data;
            chart = render(canvas, data, chart);

            stats.avg.textContent = data.stats.avg !== null ? formatMs(data.stats.avg) : '—';
            stats.min.textContent = data.stats.min !== null ? formatMs(data.stats.min) : '—';
            stats.max.textContent = data.stats.max !== null ? formatMs(data.stats.max) : '—';
            stats.count.textContent = data.stats.count.toLocaleString('en-US');
            canvas.setAttribute('aria-label', data.stats.count
                ? `Response time over the last ${period}: average ${formatMs(data.stats.avg)}, minimum ${formatMs(data.stats.min)}, maximum ${formatMs(data.stats.max)}.`
                : `No response time measured over the last ${period}.`);

            showMessage(data.points.length ? '' : 'No measure over this period yet.');
        } catch (error) {
            // Message générique renvoyé par l'API (Zabbix indisponible), jamais d'erreur technique
            showMessage(error.response?.data?.message ?? 'Metrics are temporarily unavailable.');
        } finally {
            frame.style.opacity = '';
        }
    }

    buttons.forEach((button) => button.addEventListener('click', () => load(button.dataset.period)));
    load(root.dataset.period || '1h');
}

document.querySelectorAll('[data-response-time-chart]').forEach(initResponseTimeChart);
