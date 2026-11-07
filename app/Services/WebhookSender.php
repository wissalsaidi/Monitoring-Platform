<?php

namespace App\Services;

use App\Enums\WebhookType;
use App\Exceptions\WebhookDeliveryException;
use App\Models\Webhook;
use App\Rules\PublicUrl;
use App\Support\HostResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Envoi HTTP d'un webhook (SCRUM-76) : POST JSON signé, 5 s maximum, sans suivre les redirections.
 *
 * Signature : X-Platform-Signature = « sha256= » + HMAC-SHA256(secret, timestamp + « . » + corps). Le timestamp est
 * signé avec le corps : un message rejoué plus tard, ou avec un autre timestamp, ne passe pas la vérification.
 *
 * SSRF : l'adresse est résolue et revérifiée à chaque envoi (DNS rebinding), puis la connexion est faite sur cette
 * adresse-là (CURLOPT_RESOLVE) : le nom ne peut pas pointer ailleurs entre la vérification et l'appel.
 */
class WebhookSender
{
    public const TIMEOUT_SECONDS = 5;

    public const USER_AGENT = 'MonitoringPlatform-Webhook/1';

    /** Longueur de l'extrait de réponse gardé en cas d'échec */
    public const EXCERPT = 200;

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return int code HTTP (2xx)
     *
     * @throws WebhookDeliveryException
     */
    public function send(Webhook $webhook, string $event, array $payload, string $deliveryId): int
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();

        // Slack et Teams ne vérifient pas de signature : l'URL secrète fait office de jeton (SCRUM-77)
        $headers = $webhook->type === WebhookType::Generic ? [
            'X-Platform-Event' => $event,
            'X-Platform-Delivery' => $deliveryId,
            'X-Platform-Timestamp' => $timestamp,
            'X-Platform-Signature' => self::signature((string) $webhook->secret, $timestamp, $body),
        ] : [];

        $request = Http::withBody($body, 'application/json')
            ->withHeaders(['User-Agent' => self::USER_AGENT, ...$headers])
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::TIMEOUT_SECONDS)
            ->withOptions(['allow_redirects' => false, ...$this->pinnedAddress((string) $webhook->url)]);

        try {
            $response = $request->post((string) $webhook->url);
        } catch (ConnectionException $e) {
            // Le message de Guzzle contient l'URL : jamais repris tel quel
            throw new WebhookDeliveryException(str_contains(strtolower($e->getMessage()), 'timed out')
                ? 'Timeout: no answer within '.self::TIMEOUT_SECONDS.' s.'
                : 'Connection failed.');
        }

        if ($response->redirect()) {
            throw new WebhookDeliveryException("HTTP {$response->status()}: redirect not followed.");
        }
        if (! $response->successful()) {
            // Page HTML d'erreur : balises remplacées par des espaces, pour garder les mots séparés
            $excerpt = Str::limit(trim(preg_replace(['/<[^>]*>/', '/\s+/'], ' ', $response->body())), self::EXCERPT);

            throw new WebhookDeliveryException("HTTP {$response->status()}".($excerpt !== '' ? ": {$excerpt}" : '.'));
        }

        return $response->status();
    }

    /** « sha256= » + HMAC-SHA256 hexadécimal de « timestamp.corps » */
    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Adresse vérifiée et imposée à curl. Hôtes de démo (monitoring.webhook_allowed_hosts) : DNS de Docker, sans contrôle.
     *
     * @return array<string, mixed> options Guzzle
     *
     * @throws WebhookDeliveryException
     */
    private function pinnedAddress(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '') {
            throw new WebhookDeliveryException('Invalid webhook URL.');
        }

        if (in_array($host, array_map('strtolower', config('monitoring.webhook_allowed_hosts', [])), true)) {
            return [];
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);
        if ($ips === []) {
            throw new WebhookDeliveryException('The webhook host cannot be found.');
        }
        foreach ($ips as $ip) {
            if (! PublicUrl::isPublic($ip)) {
                throw new WebhookDeliveryException('Refused: the webhook host now points to a non-public address.');
            }
        }

        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'http' ? 80 : 443);
        $ip = str_contains($ips[0], ':') ? "[{$ips[0]}]" : $ips[0];

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]];
    }
}
