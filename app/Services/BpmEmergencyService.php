<?php

namespace App\Services;

use App\Models\InsBpmCount;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BpmEmergencyService
{
    public function getEmergencyDataByMachine(string $plant, string $line, Carbon $date): array
    {
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();
        
        $records = InsBpmCount::whereBetween('created_at', [$from, $to])
            ->where('plant', $plant)
            ->where('line', $line)
            ->orderBy('created_at', 'desc')
            ->get();
        
        $latestRecords = $this->getLatestRecordsByMachineCondition($records);
        
        return $this->formatEmergencyData($latestRecords);
    }
    
    private function getLatestRecordsByMachineCondition(Collection $records): Collection
    {
        return $records->groupBy(fn($item) => $item->machine . '-' . $item->condition)
            ->map->first();
    }
    
    private function formatEmergencyData(Collection $latestRecords): array
    {
        return $latestRecords->pluck('machine')
            ->unique()
            ->sort()
            ->values()
            ->map(fn($machine) => [
                'machine' => $machine,
                'hot' => $latestRecords->where('machine', $machine)->where('condition', 'hot')->first()->cumulative ?? 0,
                'cold' => $latestRecords->where('machine', $machine)->where('condition', 'cold')->first()->cumulative ?? 0,
                'total' => ($latestRecords->where('machine', $machine)->where('condition', 'hot')->first()->cumulative ?? 0) 
                         + ($latestRecords->where('machine', $machine)->where('condition', 'cold')->first()->cumulative ?? 0),
            ])
            ->values()
            ->toArray();
    }
}