<?php

namespace App\Mcp\Tools;

use App\Models\CharityTransactions;
use App\Models\Devices;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('charity-overview')]
#[Title('Charity Overview')]
#[Description('Returns read-only donation totals and device counts for a charity dashboard date range.')]
class CharityOverviewTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = $this->dateFrom($validated['from'] ?? null);
        $to = $this->dateTo($validated['to'] ?? null);

        $transactions = CharityTransactions::query()
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()]);

        $successStatuses = ['success', 'successful'];
        $failedStatuses = ['fail', 'failed'];

        $successQuery = (clone $transactions)->whereIn('status', $successStatuses);
        $failedQuery = (clone $transactions)->whereIn('status', $failedStatuses);
        $pendingQuery = (clone $transactions)->whereNotIn('status', [
            ...$successStatuses,
            ...$failedStatuses,
        ]);

        $overview = [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'totals' => [
                'transactions_count' => (int) (clone $transactions)->count(),
                'success_count' => (int) (clone $successQuery)->count(),
                'success_amount' => $this->money((clone $successQuery)->sum('total_amount')),
                'failed_count' => (int) (clone $failedQuery)->count(),
                'failed_amount' => $this->money((clone $failedQuery)->sum('total_amount')),
                'pending_count' => (int) (clone $pendingQuery)->count(),
                'pending_amount' => $this->money((clone $pendingQuery)->sum('total_amount')),
            ],
            'devices' => [
                'active_count' => (int) Devices::query()->where('status', 'active')->count(),
                'total_count' => (int) Devices::query()->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        return Response::structured($overview);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()
                ->format('date')
                ->description('Start date in YYYY-MM-DD format. Defaults to 30 days ago.'),
            'to' => $schema->string()
                ->format('date')
                ->description('End date in YYYY-MM-DD format. Defaults to today.'),
        ];
    }

    private function dateFrom(?string $date): Carbon
    {
        return $date
            ? Carbon::createFromFormat('Y-m-d', $date)->startOfDay()
            : now()->subDays(29)->startOfDay();
    }

    private function dateTo(?string $date): Carbon
    {
        return $date
            ? Carbon::createFromFormat('Y-m-d', $date)->endOfDay()
            : now()->endOfDay();
    }

    private function money(mixed $amount): float
    {
        return round((float) $amount, 3);
    }
}
