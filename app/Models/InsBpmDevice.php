<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

class InsBpmDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'line',
        'ip_address',
        'config',
        'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Boot the model to add custom validation rules
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($device) {
            $device->validateUniqueLines();
        });
    }

    /**
     * Validate that lines in config are globally unique
     */
    public function validateUniqueLines()
    {
        if (!$this->config) {
            return;
        }

        $lines = collect($this->config)->pluck('line')->map(function ($line) {
            return strtoupper(trim($line));
        });

        // Check for duplicates within the same device
        if ($lines->count() !== $lines->unique()->count()) {
            throw new \InvalidArgumentException('Duplicate lines found within the same device configuration.');
        }

        // Check for global uniqueness across all devices
        foreach ($lines as $line) {
            $existingDevice = static::where('id', '!=', $this->id ?? 0)
                ->get()
                ->filter(function ($device) use ($line) {
                    if (!$device->config) return false;
                    
                    return collect($device->config)->pluck('line')->map(function ($configLine) {
                        return strtoupper(trim($configLine));
                    })->contains($line);
                })
                ->first();

            if ($existingDevice) {
                throw new \InvalidArgumentException("Line '{$line}' is already used by device '{$existingDevice->name}'.");
            }
        }
    }

    /**
     * Get all counts for lines managed by this device
     * Note: This is a custom relationship since one device can manage multiple lines
     */
    public function counts()
    {
        $lines = $this->getLines();
        return InsDwpCount::whereIn('line', $lines);
    }

    /**
     * Get uptime logs for this device
     */
    public function uptimeLogs(): HasMany
    {
        return $this->hasMany(LogDwpUptime::class, 'ins_dwp_device_id');
    }

    /**
     * Get the latest uptime log
     */
    public function latestUptimeLog()
    {
        return $this->hasMany(LogDwpUptime::class, 'ins_dwp_device_id')
            ->latest('logged_at')
            ->first();
    }

    /**
     * Get current status of the device
     */
    public function getCurrentStatus(): ?string
    {
        return LogDwpUptime::getLatestStatus($this->id);
    }

    /**
     * Calculate uptime percentage
     */
    public function getUptimePercentage(int $hours = 24): float
    {
        return LogDwpUptime::calculateUptime($this->id, $hours);
    }

    /**
     * Get lines managed by this device
     */
    public function getLines(): array
    {
        if (!$this->config) {
            return [];
        }

        return collect($this->config)->pluck('line')->map(function ($line) {
            return strtoupper(trim($line));
        })->toArray();
    }

    /**
     * Get modbus configuration for a specific line
     */
    public function getLineConfig(string $line): ?array
    {
        $line = strtoupper(trim($line));
        if (!$this->config) {
            return null;
        }

        return collect($this->config)->first(function ($config) use ($line) {
            return strtoupper(trim($config['line'])) === $line;
        });
    }

    /**
     * Check if device manages a specific line
     */
    public function managesLine(string $line): bool
    {
        return in_array(strtoupper(trim($line)), $this->getLines());
    }

    /**
     * Get latest count for each line managed by this device
     */
    public function latestCounts(): array
    {
        $lines = $this->getLines();
        $counts = [];

        foreach ($lines as $line) {
            $count = InsDwpCount::where('line', $line)
                ->latest('created_at')
                ->first();
            
            $counts[$line] = $count;
        }

        return $counts;
    }

    /**
     * Scope for active devices
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get all machines from the config with their standard references
     * Returns an array of machines from all lines with their standard addresses
     */
    public function getMachines(): array
    {
        if (!$this->config) {
            return [];
        }

        $machines = [];
        foreach ($this->config as $lineConfig) {
            if (isset($lineConfig['list_mechine'])) {
                foreach ($lineConfig['list_mechine'] as $machine) {
                    $machines[] = array_merge($machine, [
                        'line' => $lineConfig['line'],
                    ]);
                }
            }
        }

        return $machines;
    }

    /**
     * Get machine configuration for a specific machine name and line
     */
    public function getMachineConfig(string $line, string $machineName): ?array
    {
        $lineConfig = $this->getLineConfig($line);
        if (!$lineConfig || !isset($lineConfig['list_mechine'])) {
            return null;
        }

        return collect($lineConfig['list_mechine'])->first(function ($machine) use ($machineName) {
            return $machine['name'] === $machineName;
        });
    }

    /**
     * Get standard values for a machine based on the standard address mapping
     * This links the machine's standard addresses to InsDwpStandardPV records
     */
    public function getMachineStandards(string $line, string $machineName): array
    {
        $machine = $this->getMachineConfig($line, $machineName);
        if (!$machine) {
            return [];
        }

        $standards = [];
        
        // Map standard addresses to their corresponding InsDwpStandardPV records
        // Based on the sample data, machines reference standards by address
        $standardTypes = [
            'th_max' => 'addr_std_th_max',
            'th_min' => 'addr_std_th_min',
            'side_max' => 'addr_std_side_max',
            'side_min' => 'addr_std_side_min',
        ];

        foreach ($standardTypes as $type => $addressKey) {
            if (isset($machine[$addressKey])) {
                // You can extend this to lookup InsDwpStandardPV by address or name
                $standards[$type] = [
                    'address' => $machine[$addressKey],
                    'type' => $type,
                ];
            }
        }

        return $standards;
    }

    /**
     * Get DWP alarm configuration for a specific line
     */
    public function getDwpAlarmConfig(string $line): ?array
    {
        $lineConfig = $this->getLineConfig($line);
        return $lineConfig['dwp_alarm'] ?? null;
    }
}
