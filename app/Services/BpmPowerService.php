<?php

namespace App\Services;

use App\Models\InsBpmDevice;
use App\Models\InsBpmPower;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BpmPowerService
{
    public function getPowerDataByMachine(InsBpmDevice $device, Carbon $date): array
    {
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();
        
        $records = InsBpmPower::whereBetween('created_at', [$from, $to])
            ->where('device_id', $device->id)
            ->orderBy('created_at', 'desc')
            ->get();
        
        $latestRecords = $this->getLatestRecordsByMachineCondition($records);
        
        return $this->formatPowerData($latestRecords);
    }
    
    private function getLatestRecordsByMachineCondition(Collection $records): Collection
    {
        return $records->groupBy(fn($item) => $item->machine . '-' . $item->condition)
            ->map->first();
    }
    
    private function formatPowerData(Collection $latestRecords): array
    {
        return $latestRecords->pluck('machine')
            ->unique()
            ->sort()
            ->values()
            ->map(fn($machine) => [
                'machine' => $machine,
                'power' => $latestRecords->where('machine', $machine)->where('condition', 'on')->first()->cumulative ?? 0,
                'off' => $latestRecords->where('machine', $machine)->where('condition', 'off')->first()->cumulative ?? 0,
                'total' => ($latestRecords->where('machine', $machine)->where('condition', 'on')->first()->cumulative ?? 0) 
                         + ($latestRecords->where('machine', $machine)->where('condition', 'off')->first()->cumulative ?? 0),
            ])
            ->values()
            ->toArray();
    }
}