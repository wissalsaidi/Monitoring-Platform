<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\TeamController as AdminTeamController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BreakGlassController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GrafanaAuthController;
use App\Http\Controllers\MonitoringTargetController;
use App\Http\Controllers\NotificationHistoryController;
use App\Http\Controllers\NotificationRuleController;
use App\Http\Controllers\OidcController;
use App\Http\Controllers\TargetGrafanaController;
use App\Http\Controllers\TargetMetricsController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\WaitingForAccessController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\EnsureTwoFactorForAdmins;
use App\Http\Middleware\EnsureUserHasTeam;
use Illuminate\Support\Facades\Route;

// Les routes de connexion / inscription / déconnexion sont fournies par Fortify.

// Email vérifié obligatoire (SCRUM-32) : sinon redirection vers la page « Verify your email »
// Auth proxy de Grafana (SCRUM-48), appelé par nginx avant chaque requête vers /grafana/ : 200 / 401 / 403, sans redirection
Route::get('/_grafana_auth', GrafanaAuthController::class)->name('grafana.auth');

// SSO OpenID Connect (SCRUM-88, 89) : départ vers le fournisseur et retour (URL de retour fixe, déclarée chez lui).
// Le retour sert aussi à la confirmation d'identité d'une personne connectée (reauth) : pas de middleware guest.
Route::middleware(['guest', 'throttle:20,1'])->group(function () {
    Route::get('/auth/oidc/redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
    Route::get('/auth/oidc/no-access', [OidcController::class, 'noAccess'])->name('oidc.no-access');
});
Route::get('/auth/oidc/callback', [OidcController::class, 'callback'])->middleware('throttle:20,1')->name('oidc.callback');
// Compte de secours (SCRUM-90) : connexion par mot de passe quand le SSO est obligatoire (404 sinon)
Route::middleware('guest')->group(function () {
    Route::get('/login/break-glass', [BreakGlassController::class, 'show'])->name('break-glass');
    Route::post('/login/break-glass', [BreakGlassController::class, 'store'])->middleware('throttle:login')->name('break-glass.store');
});
Route::get('/auth/oidc/reauth', [OidcController::class, 'reauth'])->middleware(['auth', 'throttle:10,1'])->name('oidc.reauth');

// Lien d'invitation reçu par email (SCRUM-59, 83), public : connexion pour un compte existant, création du compte
// pour une adresse inconnue. Signature vérifiée dans le contrôleur pour afficher un message clair.
Route::get('/invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])
    ->whereNumber('invitation')->name('invitations.accept');
Route::post('/invitations/{invitation}/accept', [TeamInvitationController::class, 'register'])
    ->whereNumber('invitation')->middleware(['guest', 'throttle:6,1'])->name('invitations.register');

Route::middleware(['auth', 'verified', EnsureTwoFactorForAdmins::class])->group(function () {
    // Sans team (SCRUM-82) : seules ces pages (et le lien d'invitation) restent accessibles, le reste renvoie sur « Waiting for access »
    Route::get('/waiting', WaitingForAccessController::class)->name('access.waiting');
    // Double authentification (SCRUM-86) : page d'activation, accessible même quand la 2FA est exigée
    Route::get('/account/two-factor', [TwoFactorController::class, 'show'])->name('account.two-factor');
    Route::post('/account/two-factor', [TwoFactorController::class, 'enable'])->middleware('throttle:6,1')->name('account.two-factor.enable');
    // Mon compte et droit à l'effacement (RGPD-5, SCRUM-63)
    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::delete('/account', [AccountController::class, 'destroy'])->middleware('throttle:6,1')->name('account.destroy');
    // Délier le SSO (SCRUM-89), seulement s'il reste un mot de passe
    Route::delete('/account/sso', [AccountController::class, 'unlinkSso'])->middleware('throttle:6,1')->name('account.sso.destroy');

    // Inviter / annuler (gate team.invite) : aussi pour un Super Admin qui n'est membre d'aucune team (SCRUM-83)
    Route::post('/teams/{team}/invitations', [TeamInvitationController::class, 'store'])
        ->middleware('throttle:20,1')->name('teams.invitations.store');
    Route::delete('/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');

    // Console d'administration (SCRUM-84) : Super Admin seulement, même sans team
    Route::prefix('admin')->name('admin.')->middleware('can:platform.users.manage')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/users/invitations', [AdminUserController::class, 'invite'])->middleware('throttle:20,1')->name('users.invite');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::post('/users/{user}/teams', [AdminUserController::class, 'addToTeam'])->name('users.teams.store');
        Route::patch('/users/{user}/teams/{team}', [AdminUserController::class, 'updateRole'])->name('users.teams.update');
        Route::delete('/users/{user}/teams/{team}', [AdminUserController::class, 'removeFromTeam'])->name('users.teams.destroy');
        Route::post('/users/{user}/super-admin', [AdminUserController::class, 'promote'])->name('users.super-admin.store');
        Route::delete('/users/{user}/super-admin', [AdminUserController::class, 'demote'])->name('users.super-admin.destroy');
        Route::post('/users/{user}/deactivate', [AdminUserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])->name('users.reactivate');
        Route::delete('/users/{user}/two-factor', [AdminUserController::class, 'resetTwoFactor'])->name('users.two-factor.reset');
        Route::post('/users/{user}/password-reset', [AdminUserController::class, 'forcePasswordReset'])
            ->middleware('throttle:10,1')->name('users.password-reset');
    });
    // Teams et audit de la plateforme (SCRUM-85)
    Route::prefix('admin')->name('admin.')->middleware('can:platform.teams.manage')->group(function () {
        Route::get('/teams', [AdminTeamController::class, 'index'])->name('teams.index');
        Route::post('/teams', [AdminTeamController::class, 'store'])->name('teams.store');
        Route::get('/teams/{team}', [AdminTeamController::class, 'show'])->name('teams.show');
        Route::patch('/teams/{team}', [AdminTeamController::class, 'update'])->name('teams.update');
        Route::delete('/teams/{team}', [AdminTeamController::class, 'destroy'])->middleware('throttle:10,1')->name('teams.destroy');
        // Groupes de l'annuaire (SCRUM-92)
        Route::post('/teams/{team}/directory-groups', [AdminTeamController::class, 'storeGroup'])->name('teams.groups.store');
        Route::delete('/teams/{team}/directory-groups/{mapping}', [AdminTeamController::class, 'destroyGroup'])->name('teams.groups.destroy');
    });
    Route::get('/admin/audit', [AdminAuditController::class, 'index'])->middleware('can:platform.audit.view')->name('admin.audit');

    Route::middleware(EnsureUserHasTeam::class)->group(function () {
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

        // Règles de notification (SCRUM-55) : lecture pour tous les membres, modification Admin et Manager (notification.manage)
        Route::get('/notifications', [NotificationRuleController::class, 'current'])->name('teams.notifications.current');
        Route::get('/teams/{team}/notifications', [NotificationRuleController::class, 'index'])->name('teams.notifications.index');
        Route::put('/teams/{team}/notifications', [NotificationRuleController::class, 'update'])->name('teams.notifications.update');
        // Historique des notifications (SCRUM-56) : tout membre de la team
        Route::get('/teams/{team}/notifications/history', NotificationHistoryController::class)->name('teams.notifications.history');

        // Webhooks de la team (SCRUM-78) : le webhook doit appartenir à la team de l'URL (scopeBindings)
        Route::prefix('/teams/{team}/notifications/webhooks')->name('teams.notifications.webhooks.')->scopeBindings()->group(function () {
            Route::get('/', [WebhookController::class, 'index'])->name('index');
            Route::post('/', [WebhookController::class, 'store'])->name('store');
            Route::get('/{webhook}/edit', [WebhookController::class, 'edit'])->name('edit');
            Route::put('/{webhook}', [WebhookController::class, 'update'])->name('update');
            Route::patch('/{webhook}/active', [WebhookController::class, 'toggle'])->name('toggle');
            Route::delete('/{webhook}', [WebhookController::class, 'destroy'])->name('destroy');
            Route::post('/{webhook}/secret', [WebhookController::class, 'rotateSecret'])->name('secret');
            Route::post('/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1')->name('test');
        });

        // Journal d'audit de la team (SCRUM-60) : Admin (permission audit.view)
        Route::get('/audit', [AuditLogController::class, 'current'])->name('teams.audit.current');
        Route::get('/teams/{team}/audit', [AuditLogController::class, 'index'])->name('teams.audit.index');
    });
});
