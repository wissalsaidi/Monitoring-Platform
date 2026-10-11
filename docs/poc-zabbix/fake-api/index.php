<?php
/**
 * Fausse "Payment API" pour la démo.
 *
 *   GET /health                    -> répond (avec la latence et le mode réglés)
 *   GET /control?delay=1500        -> règle la latence en millisecondes
 *   GET /control?mode=error        -> /health renvoie une erreur 500
 *   GET /control?mode=ok&delay=0   -> retour à la normale
 *   GET /control                   -> affiche les réglages actuels
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
