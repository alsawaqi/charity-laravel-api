<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

trait ResolvesCharityReportFilters
{
    protected function charityReportTimezone(): string
    {
        return 'Asia/Muscat';
    }

    protected function charitySuccessStatuses(): array
    {
        return ['success', 'successful'];
    }

    protected function charityFailedStatuses(): array
    {
        return ['fail', 'failed'];
    }

    protected function charityCancelledStatuses(): array
    {
        return ['Cancelled'];
    }

    protected function resolveCharityDateRange(
        string $range = '7d',
        ?string $from = null,
        ?string $to = null
    ): array {
        $tz = $this->charityReportTimezone();
        $now = Carbon::now($tz);
        $end = $now->copy()->endOfDay();

        switch ($range) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;

            case '30d':
                $start = $end->copy()->subDays(29)->startOfDay();
                break;

            case '6m':
                $start = $end->copy()->subMonthsNoOverflow(6)->startOfDay();
                break;

            case 'custom':
                if (!$from || !$to) {
                    throw new InvalidArgumentException('From and to dates are required for custom range.');
                }

                $start = Carbon::createFromFormat('Y-m-d', (string) $from, $tz)->startOfDay();
                $end = Carbon::createFromFormat('Y-m-d', (string) $to, $tz)->endOfDay();
                break;

            case '7d':
            default:
                $start = $end->copy()->subDays(6)->startOfDay();
                break;
        }

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [
            'range' => $range,
            'start' => $start,
            'end' => $end,
            'tz' => $tz,
            'from' => $from,
            'to' => $to,
        ];
    }

    protected function resolveCharityRangeFromRequest(
        Request $request,
        string $defaultRange = '7d',
        bool $allowImplicitCustom = false
    ): array {
        $range = (string) $request->input('range', $defaultRange);
        $from = $request->input('from');
        $to = $request->input('to');

        if ($allowImplicitCustom && !$request->filled('range') && $from && $to) {
            $range = 'custom';
        }

        return $this->resolveCharityDateRange($range, $from, $to);
    }

    protected function applyNormalizedStatusFilter($query, ?string $status)
    {
        $normalized = strtolower(trim((string) $status));

        return match ($normalized) {
            '', 'all' => $query,
            'success', 'successful' => $query->whereIn('status', $this->charitySuccessStatuses()),
            'fail', 'failed' => $query->whereIn('status', $this->charityFailedStatuses()),
            'cancelled', 'canceled' => $query->whereIn('status', $this->charityCancelledStatuses()),
            default => $query->where('status', $status),
        };
    }

    protected function applyDateOnlyRange($query, string $column, Carbon $start, Carbon $end)
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        return $query->whereRaw("{$column}::date >= ? AND {$column}::date <= ?", [
            $startDate,
            $endDate,
        ]);
    }
}