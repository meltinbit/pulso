<?php

namespace App\Http\Controllers\Concerns;

use App\Models\GaProperty;
use Illuminate\Http\Request;

trait HasActiveProperty
{
    private const PERIODS = [
        '7d' => ['start' => '7daysAgo', 'compare' => '14daysAgo', 'compareEnd' => '8daysAgo', 'label' => '7 days'],
        '14d' => ['start' => '14daysAgo', 'compare' => '28daysAgo', 'compareEnd' => '15daysAgo', 'label' => '14 days'],
        '30d' => ['start' => '30daysAgo', 'compare' => '60daysAgo', 'compareEnd' => '31daysAgo', 'label' => '30 days'],
        '90d' => ['start' => '90daysAgo', 'compare' => '180daysAgo', 'compareEnd' => '91daysAgo', 'label' => '90 days'],
    ];

    protected function getActiveProperty(Request $request): ?GaProperty
    {
        $user = $request->user();
        $activeId = session('active_property_id');

        if ($activeId) {
            $property = $user->gaProperties()->with('gaConnection')->find($activeId);
            if ($property) {
                return $property;
            }
        }

        return $user->gaProperties()->with('gaConnection')->where('is_active', true)->first();
    }

    protected function getDateRange(Request $request): array
    {
        $period = $request->get('period', '30d');
        if (! isset(self::PERIODS[$period])) {
            $period = '30d';
        }

        return ['period' => $period, 'range' => self::PERIODS[$period]];
    }

    protected function periodLabels(): array
    {
        return array_map(fn ($p) => $p['label'], self::PERIODS);
    }
}
