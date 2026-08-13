<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(): View
    {
        return view('administration.settings', ['settings' => SystemSetting::orderBy('group_code')->orderBy('setting_key')->get()]);
    }

    public function update(Request $request, SystemSetting $setting, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['value' => ['nullable', 'string', 'max:2000'], 'reason' => ['required', 'string', 'max:1000']]);
        $before = $setting->value_json;
        $after = match ($setting->data_type) {
            'INTEGER' => filled($data['value']) ? (int) $data['value'] : null,
            'MONEY' => filled($data['value']) ? round((float) $data['value'], 2) : null,
            default => filled($data['value']) ? $data['value'] : null,
        };
        DB::transaction(function () use ($setting, $request, $before, $after, $data, $audit): void {
            $setting->update(['value_json' => $after, 'updated_by_user_id' => $request->user()->id]);
            DB::table('configuration_changes')->insert([
                'system_setting_id' => $setting->id,
                'changed_by_user_id' => $request->user()->id,
                'before_value_json' => json_encode($before),
                'after_value_json' => json_encode($after),
                'reason' => $data['reason'],
                'changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $audit->record('SYSTEM_SETTING_CHANGED', $setting, reason: $data['reason'], before: ['value' => $before], after: ['value' => $after]);
        });

        return back()->with('status', 'Configuration updated prospectively with before/after history.');
    }
}
