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
     * Validate that line is unique
     */
    public function validateUniqueLines()
    {
        if (!$this->line) {
            return;
        }

        $normalizedLine = strtoupper(trim($this->line));

        // Check for global uniqueness across all devices
        $existingDevice = static::where('id', '!=', $this->id ?? 0)
            ->whereRaw('UPPER(TRIM(line)) = ?', [$normalizedLine])
            ->first();

        if ($existingDevice) {
            throw new \InvalidArgumentException("Line '{$this->line}' is already used by device '{$existingDevice->name}'.");
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
        if (!$this->line) {
            return [];
        }

        return [strtoupper(trim($this->line))];
    }

    /**
     * Get modbus configuration for a specific line
     */
    public function getLineConfig(string $line): ?array
    {
        $line = strtoupper(trim($line));
        $deviceLine = strtoupper(trim($this->line));
        
        // Check if this device manages the requested line
        if ($deviceLine !== $line) {
            return null;
        }

        return $this->config ?? null;
    }

    /**
     * Check if device manages a specific line
     */
    public function managesLine(string $line): bool
    {
        return strtoupper(trim($this->line)) === strtoupper(trim($line));
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
     * Get all machines from the config
     * Returns an array of machines with their configuration
     */
    public function getMachines(): array
    {
        if (!$this->config || !isset($this->config['list_machine'])) {
            return [];
        }

        $machines = [];
        foreach ($this->config['list_machine'] as $machine) {
            $machines[] = array_merge($machine, [
                'line' => $this->line,
            ]);
        }

        return $machines;
    }

    /**
     * Get machine configuration for a specific machine name and line
     */
    public function getMachineConfig(string $line, string $machineName): ?array
    {
        // Check if this device manages the requested line
        if (!$this->managesLine($line)) {
            return null;
        }

        if (!$this->config || !isset($this->config['list_machine'])) {
            return null;
        }

        return collect($this->config['list_machine'])->first(function ($machine) use ($machineName) {
            return $machine['name'] === $machineName;
        });
    }

    /**
     * Get machine by name
     */
    public function getMachineByName(string $machineName): ?array
    {
        if (!$this->config || !isset($this->config['list_machine'])) {
            return null;
        }

        return collect($this->config['list_machine'])->first(function ($machine) use ($machineName) {
            return $machine['name'] === $machineName;
        });
    }
}
