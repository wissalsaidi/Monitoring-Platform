<?php
/**
 * Fausse "Payment API" pour la démo.
 *
 *   GET /health                    -> répond (avec la latence et le mode réglés)
 *   GET /control?delay=1500        -> règle la latence en millisecondes
 *   GET /control?mode=error        -> /health renvoie une erreur 500
 *   GET /control?mode=ok&delay=0   -> retour à la normale
 *   GET /control                   -> affiche les réglages actuels
 *
 * Receveur de webhooks pour la démo (SCRUM-76) :
 *   POST /webhook                  -> garde la requête (en-têtes X-Platform-*, corps) et répond 200
 *   POST /webhook/fail             -> répond 500 (page HTML), pour voir les nouveaux essais
 *   POST /webhook/redirect         -> répond 302 vers une adresse interne (ne doit pas être suivie)
 *   GET  /webhook/received         -> les 20 dernières requêtes reçues (JSON)
 */

$stateFile = sys_get_temp_dir() . '/fake-api-state.json';
$state = is_file($stateFile)
    ? json_decode(file_get_contents($stateFile), true)
    : ['delay' => 0, 'mode' => 'ok'];

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/control') {
    if (isset($_GET['delay'])) {
        $state['delay'] = max(0, min(30000, (int) $_GET['delay']));
    }
    if (isset($_GET['mode']) && in_array($_GET['mode'], ['ok', 'error'], true)) {
        $state['mode'] = $_GET['mode'];
    }
    file_put_contents($stateFile, json_encode($state));
    echo json_encode(['settings' => $state]);
    return;
}

// ─── Receveur de webhooks (SCRUM-76) ───────────────────────────────────
$inbox = sys_get_temp_dir() . '/fake-api-webhooks.json';

if ($path === '/webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $received = is_file($inbox) ? json_decode(file_get_contents($inbox), true) : [];
    $headers = array_filter(getallheaders(), fn ($name) => stripos($name, 'X-Platform-') === 0 || in_array(strtolower($name), ['content-type', 'user-agent'], true), ARRAY_FILTER_USE_KEY);
    $received[] = ['received_at' => gmdate('c'), 'headers' => $headers, 'body' => file_get_contents('php://input')];
    file_put_contents($inbox, json_encode(array_slice($received, -20)));
    echo json_encode(['received' => true]);
    return;
}
if ($path === '/webhook/fail') {
    http_response_code(500);
    header('Content-Type: text/html');
    echo '<html><body><h1>Internal Server Error</h1><p>The receiver is down.</p></body></html>';
    return;
}
if ($path === '/webhook/redirect') {
    http_response_code(302);
    header('Location: http://169.254.169.254/latest/meta-data/');
    return;
}
if ($path === '/webhook/received') {
    echo is_file($inbox) ? file_get_contents($inbox) : '[]';
    return;
}

if ($path === '/health') {
    usleep($state['delay'] * 1000);

    if ($state['mode'] === 'error') {
        http_response_code(500);
        echo json_encode(['status' => 'error']);
        return;
    }

    echo json_encode(['status' => 'ok', 'service' => 'payment-api', 'delay_ms' => $state['delay']]);
    return;
}

http_response_code(404);
echo json_encode(['error' => 'not found']);
