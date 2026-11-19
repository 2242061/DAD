<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    public const DEFAULTS = ['online_turn_seconds' => 60, 'tournament_commission_percent' => 10];

    protected $guarded = [];

    public static function values(): array
    {
        return self::query()->whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('value', 'key')->map(fn ($value) => (int) $value)->all() + self::DEFAULTS;
    }
}
