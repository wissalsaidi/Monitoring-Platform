<?php

namespace App\Services;

use App\Exceptions\GrafanaException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * API d'administration de Grafana (SCRUM-48) : organisations, utilisateurs, datasources, dashboards.
 * Appelée côté serveur seulement, sur GRAFANA_INTERNAL_URL, avec le compte admin de Grafana (GRAFANA_ADMIN_USER /
 * GRAFANA_ADMIN_PASSWORD du .env) : ce mot de passe ne quitte jamais le serveur. Ni les paramètres ni les réponses
 * ne sont journalisés (mots de passe des datasources et des comptes).
 */
class GrafanaApi
{
    /** Rôle des utilisateurs de la plateforme dans l'organisation de leur team */
    public const ROLE_VIEWER = 'Viewer';

    public function createOrg(string $name): int
    {
        return (int) $this->send('post', '/api/orgs', ['name' => $name])->json('orgId');
    }

    public function orgExists(int $orgId): bool
    {
        return $this->send('get', "/api/orgs/{$orgId}", allowNotFound: true)->ok();
    }

    public function deleteOrg(int $orgId): void
    {
        $this->send('delete', "/api/orgs/{$orgId}", allowNotFound: true);
    }

    /** Active l'app Zabbix dans une organisation (les réglages d'une app sont propres à chaque organisation). */
    public function enablePlugin(int $orgId, string $pluginId): void
    {
        $this->send('post', "/api/plugins/{$pluginId}/settings", ['enabled' => true, 'pinned' => false], $orgId);
    }

    public function dataSourceExists(int $orgId, string $uid): bool
    {
        return $this->send('get', "/api/datasources/uid/{$uid}", orgId: $orgId, allowNotFound: true)->ok();
    }

    public function createDataSource(int $orgId, array $dataSource): void
    {
        $this->send('post', '/api/datasources', $dataSource, $orgId);
    }

    public function updateDataSource(int $orgId, string $uid, array $dataSource): void
    {
        $this->send('put', "/api/datasources/uid/{$uid}", $dataSource, $orgId);
    }

    /** Crée ou remplace le dashboard (même uid) dans l'organisation. */
    public function importDashboard(int $orgId, array $dashboard): void
    {
        $this->send('post', '/api/dashboards/db', ['dashboard' => [...$dashboard, 'id' => null], 'overwrite' => true], $orgId);
    }

    /** Crée un compte dans une organisation (sans en créer d'autre). Mot de passe inutilisé : la connexion passe par l'auth proxy. */
    public function createUser(string $login, string $name, string $email, string $password, int $orgId): int
    {
        return (int) $this->send('post', '/api/admin/users', [
            'login' => $login, 'name' => $name, 'email' => $email, 'password' => $password, 'OrgId' => $orgId,
        ])->json('id');
    }

    public function userExists(int $userId): bool
    {
        return $this->send('get', "/api/users/{$userId}", allowNotFound: true)->ok();
    }

    public function deleteUser(int $userId): void
    {
        $this->send('delete', "/api/admin/users/{$userId}", allowNotFound: true);
    }

    /** @return array<int, string> organisations du compte : id → rôle */
    public function userOrgs(int $userId): array
    {
        return collect($this->send('get', "/api/users/{$userId}/orgs")->json())
            ->mapWithKeys(fn (array $org) => [(int) $org['orgId'] => $org['role']])
            ->all();
    }

    /** Ajoute le compte à l'organisation avec ce rôle, ou lui redonne ce rôle s'il en est déjà membre. */
    public function setOrgRole(int $orgId, string $login, int $userId, string $role): void
    {
        $response = $this->send('post', "/api/orgs/{$orgId}/users", ['loginOrEmail' => $login, 'role' => $role], allowConflict: true);

        if ($response->status() === 409) {
            $this->send('patch', "/api/orgs/{$orgId}/users/{$userId}", ['role' => $role]);
        }
    }

    public function removeFromOrg(int $orgId, int $userId): void
    {
        $this->send('delete', "/api/orgs/{$orgId}/users/{$userId}", allowNotFound: true);
    }

    /**
     * @param  int|null  $orgId  organisation dans laquelle agir (en-tête X-Grafana-Org-Id)
     *
     * @throws GrafanaException Grafana injoignable, ou réponse en erreur (sauf 404 / 409 si permis)
     */
    private function send(string $method, string $path, array $data = [], ?int $orgId = null, bool $allowNotFound = false, bool $allowConflict = false): Response
    {
        try {
            $response = $this->request($orgId)->{$method}(rtrim(config('services.grafana.internal_url'), '/').$path, $data);
        } catch (ConnectionException $e) {
            throw new GrafanaException("Grafana unreachable: {$method} {$path}: {$e->getMessage()}", previous: $e);
        }

        $tolerated = ($allowNotFound && $response->notFound()) || ($allowConflict && $response->status() === 409);

        if ($response->failed() && ! $tolerated) {
            // Message de Grafana seulement (jamais la requête : elle peut contenir un mot de passe)
            throw new GrafanaException(sprintf('Grafana API error on %s %s: HTTP %d %s', strtoupper($method), $path, $response->status(), $response->json('message', '')), $response->status());
        }

        return $response;
    }

    private function request(?int $orgId): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->timeout(max(5, (int) config('services.grafana.timeout')))
            ->withBasicAuth((string) config('services.grafana.admin_user'), (string) config('services.grafana.admin_password'))
            ->when($orgId !== null, fn (PendingRequest $request) => $request->withHeaders(['X-Grafana-Org-Id' => (string) $orgId]));
    }
}
