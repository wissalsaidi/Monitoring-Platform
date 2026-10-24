<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Faux Grafana pour les tests (SCRUM-48) : état de santé et API d'administration (organisations, comptes, membres,
 * datasources, dashboards), avec un état en mémoire et la trace des appels. Appeler $this->fakeGrafanaApi() dans setUp().
 */
trait FakesGrafana
{
    protected string $grafanaInternalUrl = 'http://grafana.internal:3000/grafana';

    /** @var list<array{method: string, path: string, org: ?string, data: array}> */
    protected array $grafanaCalls = [];

    /** @var array<int, string> organisations : id → nom */
    protected array $grafanaOrgs = [1 => 'Main Org.'];

    /** @var array<int, string> comptes : id → login */
    protected array $grafanaUsers = [];

    /** @var array<int, array<int, string>> membres : id d'organisation → [id de compte → rôle] */
    protected array $grafanaMembers = [1 => []];

    /** @var array<int, array<string, array>> datasources : id d'organisation → [uid → datasource] */
    protected array $grafanaDataSources = [];

    /** @var array<int, array<string, array>> dashboards : id d'organisation → [uid → dashboard] */
    protected array $grafanaDashboards = [];

    protected bool $grafanaDown = false;

    /** Grafana injoignable (erreur de connexion, pas de réponse HTTP) */
    protected bool $grafanaUnreachable = false;

    /** @var array<string, int> réponses en erreur : "POST /api/orgs" → code HTTP */
    protected array $grafanaFailures = [];

    protected int $nextGrafanaId = 2;

    protected function fakeGrafanaApi(): void
    {
        config([
            'services.grafana.url' => 'https://grafana.example.com/grafana',
            'services.grafana.internal_url' => $this->grafanaInternalUrl,
            'services.grafana.admin_user' => 'admin',
            'services.grafana.admin_password' => 'grafana-admin-secret',
            'services.grafana.zabbix_role_id' => '1',
        ]);
        Http::fake([$this->grafanaInternalUrl.'/*' => fn (Request $request) => $this->fakeGrafana($request)]);
    }

    protected function fakeGrafana(Request $request)
    {
        $method = $request->method();
        $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen(parse_url($this->grafanaInternalUrl, PHP_URL_PATH)));
        $org = $request->header('X-Grafana-Org-Id')[0] ?? null;
        $data = $request->data();
        $this->grafanaCalls[] = ['method' => $method, 'path' => $path, 'org' => $org, 'data' => $data];

        if ($this->grafanaUnreachable) {
            throw new ConnectionException('cURL error 6: Could not resolve host: grafana');
        }
        if ($this->grafanaDown) {
            return Http::response('Bad gateway', 502);
        }
        if (isset($this->grafanaFailures["$method $path"])) {
            return Http::response(['message' => 'Failure'], $this->grafanaFailures["$method $path"]);
        }

        $notFound = Http::response(['message' => 'Not found'], 404);

        return match (true) {
            $path === '/api/health' => Http::response(['database' => 'ok', 'version' => '11.6.0']),

            $method === 'POST' && $path === '/api/orgs' => Http::response(['orgId' => $this->newOrg($data['name'])]),
            $method === 'GET' && (bool) preg_match('#^/api/orgs/(\d+)$#', $path, $m) => isset($this->grafanaOrgs[(int) $m[1]]) ? Http::response(['id' => (int) $m[1]]) : $notFound,
            $method === 'DELETE' && (bool) preg_match('#^/api/orgs/(\d+)$#', $path, $m) => $this->deleteOrg((int) $m[1]),

            $method === 'POST' && str_starts_with($path, '/api/plugins/') => Http::response(['message' => 'Plugin settings updated']),

            $method === 'GET' && (bool) preg_match('#^/api/datasources/uid/(\w+)$#', $path, $m) => isset($this->grafanaDataSources[(int) $org][$m[1]]) ? Http::response($this->grafanaDataSources[(int) $org][$m[1]]) : $notFound,
            $method === 'POST' && $path === '/api/datasources' => $this->saveDataSource((int) $org, $data),
            $method === 'PUT' && str_starts_with($path, '/api/datasources/uid/') => $this->saveDataSource((int) $org, $data),

            $method === 'POST' && $path === '/api/dashboards/db' => $this->saveDashboard((int) $org, $data),

            $method === 'POST' && $path === '/api/admin/users' => Http::response(['id' => $this->newUser($data)]),
            $method === 'GET' && (bool) preg_match('#^/api/users/(\d+)$#', $path, $m) => isset($this->grafanaUsers[(int) $m[1]]) ? Http::response(['id' => (int) $m[1]]) : $notFound,
            $method === 'DELETE' && (bool) preg_match('#^/api/admin/users/(\d+)$#', $path, $m) => $this->deleteUser((int) $m[1]),
            $method === 'GET' && (bool) preg_match('#^/api/users/(\d+)/orgs$#', $path, $m) => Http::response($this->orgsOf((int) $m[1])),

            $method === 'POST' && (bool) preg_match('#^/api/orgs/(\d+)/users$#', $path, $m) => $this->addMember((int) $m[1], $data),
            $method === 'PATCH' && (bool) preg_match('#^/api/orgs/(\d+)/users/(\d+)$#', $path, $m) => $this->setRole((int) $m[1], (int) $m[2], $data['role']),
            $method === 'DELETE' && (bool) preg_match('#^/api/orgs/(\d+)/users/(\d+)$#', $path, $m) => $this->removeMember((int) $m[1], (int) $m[2]),

            default => Http::response(['message' => "Unexpected Grafana call: $method $path"], 500),
        };
    }

    /** @return list<array> appels au faux Grafana pour cette méthode et ce chemin (expression régulière) */
    protected function grafanaCallsTo(string $method, string $pathPattern): array
    {
        return array_values(array_filter($this->grafanaCalls, fn (array $call) => $call['method'] === $method && preg_match($pathPattern, $call['path'])));
    }

    private function newOrg(string $name): int
    {
        $id = $this->nextGrafanaId++;
        $this->grafanaOrgs[$id] = $name;
        $this->grafanaMembers[$id] = [];

        return $id;
    }

    private function deleteOrg(int $id)
    {
        unset($this->grafanaOrgs[$id], $this->grafanaMembers[$id], $this->grafanaDataSources[$id], $this->grafanaDashboards[$id]);

        return Http::response(['message' => 'Organization deleted']);
    }

    private function saveDataSource(int $org, array $data)
    {
        $this->grafanaDataSources[$org][$data['uid']] = $data;

        return Http::response(['message' => 'Datasource saved']);
    }

    private function saveDashboard(int $org, array $data)
    {
        $this->grafanaDashboards[$org][$data['dashboard']['uid']] = $data['dashboard'];

        return Http::response(['status' => 'success']);
    }

    /** Comme Grafana (auto_assign_org, rôle None) : le compte est aussi rattaché à l'organisation principale. */
    private function newUser(array $data): int
    {
        $id = 1000 + $this->nextGrafanaId++;
        $this->grafanaUsers[$id] = $data['login'];
        $this->grafanaMembers[(int) $data['OrgId']][$id] = 'Viewer';
        $this->grafanaMembers[1][$id] = 'None';

        return $id;
    }

    private function deleteUser(int $id)
    {
        unset($this->grafanaUsers[$id]);
        foreach ($this->grafanaMembers as $org => $members) {
            unset($this->grafanaMembers[$org][$id]);
        }

        return Http::response(['message' => 'User deleted']);
    }

    private function orgsOf(int $userId): array
    {
        $orgs = [];
        foreach ($this->grafanaMembers as $org => $members) {
            if (isset($members[$userId])) {
                $orgs[] = ['orgId' => $org, 'name' => $this->grafanaOrgs[$org] ?? '', 'role' => $members[$userId]];
            }
        }

        return $orgs;
    }

    private function addMember(int $org, array $data)
    {
        $userId = array_search($data['loginOrEmail'], $this->grafanaUsers, true);
        if ($userId === false) {
            return Http::response(['message' => 'User not found'], 404);
        }
        if (isset($this->grafanaMembers[$org][$userId])) {
            return Http::response(['message' => 'User is already member of this organization'], 409);
        }
        $this->grafanaMembers[$org][$userId] = $data['role'];

        return Http::response(['message' => 'User added to organization']);
    }

    private function setRole(int $org, int $userId, string $role)
    {
        $this->grafanaMembers[$org][$userId] = $role;

        return Http::response(['message' => 'Organization user updated']);
    }

    private function removeMember(int $org, int $userId)
    {
        if (! isset($this->grafanaMembers[$org][$userId])) {
            return Http::response(['message' => 'User not found'], 404);
        }
        unset($this->grafanaMembers[$org][$userId]);

        return Http::response(['message' => 'User removed from organization']);
    }
}
