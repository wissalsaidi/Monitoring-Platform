<?php
/**
 * Website-01 (démo SCRUM-73) : vitrine d'une boutique, dont la lenteur se règle.
 *
 *   GET /                          -> la page (après la latence réglée)
 *   GET /control?delay=2500        -> règle la latence en millisecondes
 *   GET /control?delay=0           -> retour à la normale
 *   GET /control                   -> affiche le réglage actuel
 */

$stateFile = sys_get_temp_dir() . '/website-state.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : ['delay' => 0];

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/control') {
    if (isset($_GET['delay'])) {
        $state['delay'] = max(0, min(30000, (int) $_GET['delay']));
        file_put_contents($stateFile, json_encode($state));
    }
    header('Content-Type: application/json');
    echo json_encode(['settings' => $state]);
    return;
}

if ($path !== '/') {
    http_response_code(404);
    echo 'Not found';
    return;
}

usleep($state['delay'] * 1000);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Demo Shop</title></head>
<body>
<h1>Demo Shop</h1>
<p>Site de démonstration supervisé par la plateforme (Website-01).</p>
</body>
</html>
