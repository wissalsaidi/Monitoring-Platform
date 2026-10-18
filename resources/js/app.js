import './bootstrap';
import * as bootstrap from 'bootstrap';
import './response-time-chart';

window.bootstrap = bootstrap;

// Infobulles de la barre latérale (et de tout élément avec data-bs-toggle="tooltip")
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new bootstrap.Tooltip(el));

// Pas de onchange / onsubmit dans les vues : la Content-Security-Policy interdit les scripts inline (SCRUM-61)
document.addEventListener('change', (event) => {
    if (event.target.matches('select[data-auto-submit]')) {
        event.target.form.submit();
    }
});

document.addEventListener('submit', (event) => {
    const message = event.target.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
