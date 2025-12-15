<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class InsBpmAuth extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'actions',
    ];

    protected $casts = [
        'actions' => 'array',
    ];

    /**
     * Get the user that owns this authorization
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if user has specific action permission
     */
    public function hasAction(string $action): bool
    {
        return in_array($action, $this->actions ?? []);
    }

    /**
     * Check if user can manage devices
     */
    public function canManageDevices(): bool
    {
        return $this->hasAction('device-manage');
    }

    /**
     * Get auth record for a specific user
     */
    public static function forUser(int $userId): ?static
    {
        return static::where('user_id', $userId)->first();
    }

    /**
     * Check if a user has DWP permissions
     */
    public static function userHasPermission(int $userId, string $action): bool
    {
        // User ID 1 is always superuser
        if ($userId === 1) {
            return true;
        }

        $auth = static::forUser($userId);
        return $auth ? $auth->hasAction($action) : false;
    }

    /**
     * Get all users with DWP permissions
     */
    public static function authorizedUsers()
    {
        return static::with('user')->get()->pluck('user');
    }

    /**
     * Available actions for DWP module
     */
    public static function availableActions(): array
    {
        return [
            'device-manage' => 'Mengelola perangkat',
        ];
    }

    /**
     * Count number of actions for this auth
     */
    public function countActions()
    {
        $actions = is_string($this->actions) ? json_decode($this->actions ?? '[]', true) : ($this->actions ?? []);
        return count($actions);
    }
}
