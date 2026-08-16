@extends('layouts.app', ['title' => 'Sign in'])

@section('content')
<section class="auth-shell">
    <div class="auth-card">
        <div class="auth-icon-container">
            <x-icon name="shield-lock" />
        </div>

        <div class="auth-heading">
            <h1>Welcome back!</h1>
            <p>Sign in to continue to <strong>SPMU-ACPMP</strong></p>
        </div>

        <form class="auth-form" method="post" action="{{ route('login.store') }}">
            @csrf

            <div class="auth-field-group">
                <label for="email">CSPC Email Address</label>
                <div class="auth-input-wrapper">
                    <x-icon name="email" />
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        autocomplete="username"
                        placeholder="ictu@spmu.test"
                        required
                        autofocus
                    >
                </div>
                @error('email')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="auth-field-group">
                <label for="password">Password</label>
                <div class="auth-input-wrapper auth-password-wrapper">
                    <x-icon name="lock" />
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="••••••••••••"
                        required
                    >
                    <button
                        type="button"
                        class="auth-password-toggle"
                        data-toggle-password
                        aria-label="Toggle password visibility"
                    >
                        <x-icon name="eye" />
                    </button>
                </div>
                @error('password')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <label class="auth-checkbox">
                <input name="remember" type="checkbox" value="1">
                <span>Keep me signed in</span>
            </label>

            <button class="button auth-button" type="submit">
                <span>Sign in</span>
                <span class="auth-button-arrow">→</span>
            </button>
        </form>

        <div class="auth-divider">
            <span>Need help?</span>
        </div>

        <p class="auth-help-text">
            For employees, faculty, and staff.<br>
            Contact ICTU for account access assistance.
        </p>
    </div>
</section>
@endsection