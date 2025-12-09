<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InsDwpStandardPV extends Model
{
    use HasFactory;

    protected $table = 'ins_dwp_std_settings';

    protected $fillable = [
        'setting_name',
        'setting_value',
    ];

    protected $casts = [
        'setting_value' => 'array',
    ];

    /**
     * Get the minimum value from standard setting
     * Supports both old format [min, max] and new format with setting_std object
     */
    public function getMinAttribute()
    {
        if (is_array($this->setting_value)) {
            // New format with setting_std
            if (isset($this->setting_value['setting_std'])) {
                return $this->setting_value['setting_std']['min_s'] ?? null;
            }
            // Old format [min, max]
            return $this->setting_value[0] ?? null;
        }
        return null;
    }

    /**
     * Get the maximum value from standard setting
     * Supports both old format [min, max] and new format with setting_std object
     */
    public function getMaxAttribute()
    {
        if (is_array($this->setting_value)) {
            // New format with setting_std
            if (isset($this->setting_value['setting_std'])) {
                return $this->setting_value['setting_std']['max_s'] ?? null;
            }
            // Old format [min, max]
            return $this->setting_value[1] ?? null;
        }
        return null;
    }

    /**
     * Get side minimum value (new format only)
     */
    public function getMinSAttribute()
    {
        return $this->setting_value['setting_std']['min_s'] ?? null;
    }

    /**
     * Get side maximum value (new format only)
     */
    public function getMaxSAttribute()
    {
        return $this->setting_value['setting_std']['max_s'] ?? null;
    }

    /**
     * Get thread minimum value (new format only)
     */
    public function getMinThAttribute()
    {
        return $this->setting_value['setting_std']['min_th'] ?? null;
    }

    /**
     * Get thread maximum value (new format only)
     */
    public function getMaxThAttribute()
    {
        return $this->setting_value['setting_std']['max_th'] ?? null;
    }

    /**
     * Get all address settings (new format only)
     */
    public function getAddressesAttribute()
    {
        return $this->setting_value['setting_address'] ?? null;
    }

    /**
     * Find devices that use this standard setting
     * Searches through all device configs to find machines referencing this standard
     */
    public function getDevicesUsingStandard()
    {
        return InsDwpDevice::all()->filter(function ($device) {
            if (!$device->config) {
                return false;
            }

            foreach ($device->config as $lineConfig) {
                if (isset($lineConfig['list_mechine'])) {
                    foreach ($lineConfig['list_mechine'] as $machine) {
                        // Check if any standard address matches or if machine references this standard
                        // This is a flexible lookup that can be customized based on your needs
                        if ($this->isUsedByMachine($machine)) {
                            return true;
                        }
                    }
                }
            }

            return false;
        });
    }

    /**
     * Check if this standard is used by a specific machine configuration
     */
    protected function isUsedByMachine(array $machine): bool
    {
        // You can customize this logic based on how you want to link standards to machines
        // For example, by setting_name matching machine name or by address ranges
        $standardAddresses = [
            $machine['addr_std_th_max'] ?? null,
            $machine['addr_std_th_min'] ?? null,
            $machine['addr_std_side_max'] ?? null,
            $machine['addr_std_side_min'] ?? null,
        ];

        // Example: Check if setting_name contains the machine name
        // You can modify this logic based on your business requirements
        return false; // Customize this based on your linking logic
    }

    /**
     * Get all machines across all devices that could use this standard
     */
    public function getMachinesUsingStandard(): array
    {
        $machines = [];
        $devices = $this->getDevicesUsingStandard();

        foreach ($devices as $device) {
            foreach ($device->config as $lineConfig) {
                if (isset($lineConfig['list_mechine'])) {
                    foreach ($lineConfig['list_mechine'] as $machine) {
                        if ($this->isUsedByMachine($machine)) {
                            $machines[] = [
                                'device' => $device,
                                'line' => $lineConfig['line'],
                                'machine' => $machine,
                            ];
                        }
                    }
                }
            }
        }

        return $machines;
    }
}
