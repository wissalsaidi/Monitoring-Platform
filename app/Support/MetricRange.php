<?php

namespace App\Support;

use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Période d'un graphique (SCRUM-49) : une période prédéfinie (15m, 1h, 6h, 24h, 7d, 30d) jusqu'à maintenant, ou des
 * dates de début et de fin choisies (jours entiers, dans le fuseau d'affichage).
 */
final class MetricRange
{
    public const CUSTOM = 'custom';

    /** Une période choisie remonte au plus à un an : durée des tendances Zabbix */
    public const MAX_DAYS = 365;

    private function __construct(
        public readonly string $period,
        public readonly int $from,
        public readonly int $to,
        public readonly ?string $fromDate = null,
        public readonly ?string $toDate = null,
    ) {}

    public static function preset(string $period): self
    {
        $to = now()->timestamp;

        return new self($period, $to - MetricsService::PERIODS[$period], $to);
    }

    /** Du début du premier jour à la fin du dernier (ou maintenant, si c'est aujourd'hui) */
    public static function between(string $fromDate, string $toDate): self
    {
        $timezone = config('app.display_timezone');
        $from = CarbonImmutable::createFromFormat('Y-m-d', $fromDate, $timezone)->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $toDate, $timezone)->endOfDay();

        return new self(self::CUSTOM, $from->timestamp, min($to->timestamp, now()->timestamp), $fromDate, $toDate);
    }

    /**
     * Période demandée : ?period=24h, ou ?from=2026-10-01&to=2026-10-15 ; $default sans paramètre.
     *
     * @throws ValidationException
     */
    public static function fromRequest(Request $request, string $default = '1h'): self
    {
        $today = now()->setTimezone(config('app.display_timezone'))->toDateString();
        $oldest = now()->setTimezone(config('app.display_timezone'))->subDays(self::MAX_DAYS)->toDateString();

        $data = Validator::make($request->query(), [
            'period' => ['sometimes', Rule::in(array_keys(MetricsService::PERIODS))],
            'from' => ['required_with:to', 'date_format:Y-m-d', "after_or_equal:{$oldest}", 'before_or_equal:to'],
            'to' => ['required_with:from', 'date_format:Y-m-d', "before_or_equal:{$today}"],
        ], [
            'from.after_or_equal' => 'The start date can be at most '.self::MAX_DAYS.' days ago.',
            'from.before_or_equal' => 'The start date must be before the end date.',
            'to.before_or_equal' => 'The end date cannot be in the future.',
        ], ['from' => 'start date', 'to' => 'end date'])->validate();

        return isset($data['from'])
            ? self::between($data['from'], $data['to'])
            : self::preset($data['period'] ?? $default);
    }

    public function isCustom(): bool
    {
        return $this->period === self::CUSTOM;
    }

    /** À partir de 7 jours : tendances horaires de Zabbix (min / moyenne / max précalculés), plus rapides que l'historique */
    public function usesTrends(): bool
    {
        return $this->to - $this->from >= MetricsService::PERIODS['7d'];
    }

    /** Clé de cache : une période prédéfinie glisse avec le temps, des dates choisies non */
    public function cacheKey(): string
    {
        return $this->isCustom() ? "{$this->fromDate}_{$this->toDate}" : $this->period;
    }

    /** Paramètres d'URL de cette période (API interne, liens) */
    public function query(): array
    {
        return $this->isCustom() ? ['from' => $this->fromDate, 'to' => $this->toDate] : ['period' => $this->period];
    }

    public function label(): string
    {
        if ($this->isCustom()) {
            $format = fn (string $date) => CarbonImmutable::createFromFormat('Y-m-d', $date)->format('d/m/Y');

            return $this->fromDate === $this->toDate ? $format($this->fromDate) : $format($this->fromDate).' – '.$format($this->toDate);
        }

        return 'Last '.MetricsService::PERIOD_LABELS[$this->period];
    }
}
