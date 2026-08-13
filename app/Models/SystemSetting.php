<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['setting_key', 'value_json', 'data_type', 'group_code', 'description', 'status', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['value_json' => 'array'];
    }

    public static function value(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('setting_key', $key)->where('status', 'ACTIVE')->first();

        return $setting ? $setting->value_json : $default;
    }
}
