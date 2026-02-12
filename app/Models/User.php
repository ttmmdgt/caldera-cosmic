<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'emp_id',
        'password',
        'photo',
        'is_active',
        'seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function updatePhoto($photo)
    {
        if ($photo) {
            if ($this->photo != $photo) {
                $path = storage_path('app/livewire-tmp/'.$photo);
                // $image = Image::make($path);

                // // Resize the image to a maximum height of 600 pixels while maintaining aspect ratio
                // $image->resize(192, 192, function ($constraint) {
                //     $constraint->aspectRatio();
                //     $constraint->upsize();
                // });

                // $image->encode('jpg', 70);

                // process photo
                $manager = new ImageManager(new Driver);
                $image = $manager->read($path)
                    ->scaleDown(width: 192)
                    ->toJpeg(90);

                // Set file name and save to disk and save filename to inv_item
                $id = $this->id;
                $time = Carbon::now()->format('YmdHis');
                $rand = Str::random(5);
                $name = $id.'_'.$time.'_'.$rand.'.jpg';

                Storage::put('/public/users/'.$name, $image);

                return $this->update([
                    'photo' => $name,
                ]);
            }
        } else {
            return $this->update([
                'photo' => null,
            ]);
        }
    }

    public function prefs(): HasMany
    {
        return $this->hasMany(Pref::class);
    }

    public function inv_auths(): HasMany
    {
        return $this->hasMany(InvAuth::class);
    }

    // public function authInvArea($id): bool
    // {
    //     return $this->inv_auths->where('inv_area_id', $id)->count() ? true : false;
    // }

    public function inv_areas(): BelongsToMany
    {
        return $this->belongsToMany(InvArea::class, 'inv_auths', 'user_id', 'inv_area_id');
    }

    public function auth_inv_areas(): array
    {
        $areas = $this->id === 1
        ? InvArea::all()->toArray()
        : $this->inv_areas->toArray();

        return $areas;
    }

    /**
     * Load user preferences into session when user logs in
     */
    public function loadPreferencesToSession()
    {
        $prefs = $this->prefs()->pluck('data', 'name');

        foreach ($prefs as $name => $data) {
            session([$name => json_decode($data, true)]);
        }
    }

    /**
     * Get a specific preference value
     */
    public function getPreference($name, $default = null)
    {
        $pref = $this->prefs()->where('name', $name)->first();

        return $pref ? json_decode($pref->data, true) : $default;
    }

    public function ins_omv_metrics(): HasMany
    {
        return $this->hasMany(InsOmvMetric::class, 'user_1_id')
            ->orWhere('user_2_id', $this->id);
    }

    public function ins_rtc_auths(): HasMany
    {
        return $this->hasMany(InsRtcAuth::class);
    }

    public function ins_omv_auths(): HasMany
    {
        return $this->hasMany(InsOmvAuth::class);
    }

    public function ins_dwp_auths(): HasMany
    {
        return $this->hasMany(InsDwpAuth::class);
    }

    public function ins_rdc_auths(): HasMany
    {
        return $this->hasMany(InsRdcAuth::class);
    }

    public function ins_ldc_auths(): HasMany
    {
        return $this->hasMany(InsLdcAuth::class);
    }

    public function ins_stc_auths(): HasMany
    {
        return $this->hasMany(InsStcAuth::class);
    }

    public function ins_bpm_auths(): HasMany
    {
        return $this->hasMany(InsBpmAuth::class);
    }

    public function ins_ph_dosing_auths(): HasMany
    {
        return $this->hasMany(InsPhDosingAuth::class);
    }
}
