@extends('layouts.app', ['title' => 'Configuration'])
@section('content')
@php
    $humanizedKey = fn ($key) => ucwords(str_replace('_', ' ', (string) $key));
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Effective operational configuration</p>
        <h1>Open decisions and system settings</h1>
    </div>
</section>

<section class="content-area">
    <div class="settings-grid admin-settings-grid">
        @foreach($settings as $setting)
            @php
                $dataType = strtoupper((string) ($setting->data_type ?: 'TEXT'));
                $value = $setting->value_json;
                $valueText = $value === null ? 'Not configured' : (
                    is_bool($value) ? ($value ? 'Enabled' : 'Disabled') : (
                        is_numeric($value) ? (string) $value : (string) $value
                    )
                );
                $displayKey = match ($setting->setting_key) {
                    'overdue_grace_hours' => 'Overdue Grace Period',
                    default => $humanizedKey($setting->setting_key),
                };
            @endphp

            <form method="post" action="{{ route('administration.settings.update', $setting) }}" class="card form-grid settings-form" data-settings-form>
                @csrf
                @method('PUT')

                <div class="card-header settings-card-header">
                    <div>
                        <span class="badge">{{ $setting->group_code }}</span>
                        <h3>{{ $displayKey }}</h3>
                        @if($setting->setting_key)
                            <small class="setting-key">{{ $setting->setting_key }}</small>
                        @endif
                    </div>
                    @if($setting->status)
                        <x-status-badge :status="$setting->status" />
                    @else
                        <x-status-badge status="NOT_CONFIGURED" />
                    @endif
                </div>

                <div class="settings-summary">
                    <span>Current value</span>
                    <strong>{{ $valueText }}</strong>
                </div>

                @if(filled($setting->description))
                    <p class="settings-description">{{ $setting->description }}</p>
                @endif

                @if($dataType === 'BOOLEAN')
                    <div class="checkbox-field">
                        <label class="checkbox">
                            <input type="hidden" name="value" value="0">
                            <input type="checkbox" name="value" value="1" @checked((bool) $value)>
                            <span>Enabled</span>
                        </label>
                    </div>
                @elseif($dataType === 'INTEGER' || $dataType === 'MONEY')
                    <label>
                        Value
                        <input type="number" name="value" value="{{ $value === null ? '' : $value }}" step="{{ $dataType === 'MONEY' ? '0.01' : '1' }}" placeholder="Not configured">
                    </label>
                @else
                    <label>
                        Value
                        <input type="text" name="value" value="{{ $value === null ? '' : $value }}" placeholder="Not configured">
                    </label>
                @endif

                <label class="reason-field">
                    Reason for change
                    <textarea name="reason" required placeholder="Describe the update reason..."></textarea>
                </label>

                <div class="settings-actions">
                    <button class="button primary ui-pressable" data-save-button type="submit" disabled>Save change</button>
                    <a class="button secondary ui-pressable" href="{{ route('administration.index') }}">Back</a>
                </div>

                <small class="audit-note">Changes are recorded in the audit trail.</small>
            </form>
        @endforeach
    </div>
</section>

<script>
    document.querySelectorAll('[data-settings-form]').forEach(function (form) {
        const submit = form.querySelector('[data-save-button]');
        if (!submit) return;
        const initialState = new FormData(form);

        const updateState = function () {
            const current = new FormData(form);
            const changed = Array.from(current.entries()).some(function ([key, value]) {
                return key !== '_token' && key !== '_method' && initialState.get(key) !== value;
            });
            submit.disabled = !changed;
        };

        form.querySelectorAll('input, textarea, select').forEach(function (field) {
            field.addEventListener('input', updateState);
            field.addEventListener('change', updateState);
        });
    });
</script>
@endsection
