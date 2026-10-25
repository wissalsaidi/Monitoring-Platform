<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GrafanaAuthController;
use App\Http\Controllers\MonitoringTargetController;
use App\Http\Controllers\NotificationHistoryController;
use App\Http\Controllers\NotificationRuleController;
use App\Http\Controllers\TargetGrafanaController;
use App\Http\Controllers\TargetMetricsController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use Illuminate\Support\Facades\Route;

// Les routes de connexion / inscription / déconnexion sont fournies par Fortify.

// Email vérifié obligatoire (SCRUM-32) : sinon redirection vers la page « Verify your email »
// Auth proxy de Grafana (SCRUM-48), appelé par nginx avant chaque requête vers /grafana/ : 200 / 401 / 403, sans redirection
Route::get('/_grafana_auth', GrafanaAuthController::class)->name('grafana.auth');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/targets', [MonitoringTargetController::class, 'index'])->name('targets.index');
    Route::get('/targets/create', [MonitoringTargetController::class, 'create'])->name('targets.create');
    Route::post('/targets', [MonitoringTargetController::class, 'store'])->name('targets.store');
    Route::get('/alerts', [AlertController::class, 'active'])->name('alerts.index');
    Route::get('/alerts/history', [AlertController::class, 'history'])->name('alerts.history');
    Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');

    Route::get('/targets/{target}', [MonitoringTargetController::class, 'show'])->name('targets.show');
    Route::get('/targets/{target}/edit', [MonitoringTargetController::class, 'edit'])->name('targets.edit');
    Route::put('/targets/{target}', [MonitoringTargetController::class, 'update'])->name('targets.update');
    Route::delete('/targets/{target}', [MonitoringTargetController::class, 'destroy'])->name('targets.destroy');
    // API interne des graphiques (JSON)
    Route::get('/targets/{target}/metrics/response-time', [TargetMetricsController::class, 'responseTime'])
        ->name('targets.metrics.response-time');
    // Toutes les séries d'un type (SCRUM-72) : cpu, memory, disk, connections…
    Route::get('/targets/{target}/metrics/{metric}', [TargetMetricsController::class, 'show'])
        ->where('metric', '[a-z_]+')
        ->name('targets.metrics');
    // Dashboard Grafana de la target (SCRUM-47) : redirection, ou retour à la page si Grafana est indisponible
    Route::get('/targets/{target}/grafana', TargetGrafanaController::class)->name('targets.grafana');

    // Membres d'une team (SCRUM-59) : réservé aux Admin de la team (permissions user.*)
    Route::get('/team', [TeamMemberController::class, 'current'])->name('teams.current');
    Route::get('/teams/{team}/members', [TeamMemberController::class, 'index'])->name('teams.members.index');
    Route::patch('/teams/{team}/members/{member}', [TeamMemberController::class, 'update'])->name('teams.members.update');
    Route::delete('/teams/{team}/members/{member}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');
    Route::post('/teams/{team}/invitations', [TeamInvitationController::class, 'store'])
        ->middleware('throttle:20,1')->name('teams.invitations.store');
    Route::delete('/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');

    // Règles de notification (SCRUM-55) : lecture pour tous les membres, modification Admin et Manager (notification.manage)
    Route::get('/notifications', [NotificationRuleController::class, 'current'])->name('teams.notifications.current');
    Route::get('/teams/{team}/notifications', [NotificationRuleController::class, 'index'])->name('teams.notifications.index');
    Route::put('/teams/{team}/notifications', [NotificationRuleController::class, 'update'])->name('teams.notifications.update');
    // Historique des notifications (SCRUM-56) : tout membre de la team
    Route::get('/teams/{team}/notifications/history', NotificationHistoryController::class)->name('teams.notifications.history');

    // Mon compte et droit à l'effacement (RGPD-5, SCRUM-63)
    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::delete('/account', [AccountController::class, 'destroy'])->middleware('throttle:6,1')->name('account.destroy');

    // Journal d'audit de la team (SCRUM-60) : Admin (permission audit.view)
    Route::get('/audit', [AuditLogController::class, 'current'])->name('teams.audit.current');
    Route::get('/teams/{team}/audit', [AuditLogController::class, 'index'])->name('teams.audit.index');

    // Lien reçu par email : signature vérifiée dans le contrôleur pour afficher un message clair
    Route::get('/invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])
        ->whereNumber('invitation')->name('invitations.accept');
});
