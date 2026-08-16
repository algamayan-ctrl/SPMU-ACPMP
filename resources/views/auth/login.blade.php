@extends('layouts.app', ['title' => 'Sign in'])

@section('content')

<div class="login-page">

    <div class="login-card">

        <div class="login-emblem" aria-hidden="true">
            <x-icon name="shield-lock" />
        </div>

        <div class="login-heading">
            <h1>Welcome back!</h1>

            <p>
                Sign in to continue to
                <strong>SPMU-ACPMP</strong>
            </p>
        </div>


        <form method="POST" action="{{ route('login') }}" class="login-form">
            @csrf

            {{-- EMAIL --}}
            <div class="login-field">

                <label for="email">
                    CSPC Email Address
                </label>

                <div class="login-input-wrap @error('email') has-error @enderror">

                    <span class="login-input-icon" aria-hidden="true">
                        <x-icon name="email" />
                    </span>

                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="email"
                    >

                </div>

                @error('email')
                    <div class="login-error">
                        {{ $message }}
                    </div>
                @enderror

            </div>


            {{-- PASSWORD --}}
            <div class="login-field">

                <div class="login-label-row">
                    <label for="password">
                        Password
                    </label>

                    @if(Route::has('password.request'))
                        <a
                            href="{{ route('password.request') }}"
                            class="login-forgot"
                        >
                            Forgot password?
                        </a>
                    @endif
                </div>


                <div class="login-input-wrap @error('password') has-error @enderror">

                    <span class="login-input-icon" aria-hidden="true">
                        <x-icon name="lock" />
                    </span>

                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                    >

                    <button
                        type="button"
                        class="login-password-toggle"
                        id="loginPasswordToggle"
                        aria-label="Show password"
                        title="Show password"
                    >
                        <x-icon name="eye" />
                    </button>

                </div>

                @error('password')
                    <div class="login-error">
                        {{ $message }}
                    </div>
                @enderror

            </div>


            {{-- REMEMBER ME --}}
            <label class="login-remember">
                <input
                    type="checkbox"
                    name="remember"
                    value="1"
                    {{ old('remember') ? 'checked' : '' }}
                >

                <span>
                    Keep me signed in
                </span>
            </label>


            {{-- SIGN IN --}}
            <button
                type="submit"
                class="login-submit"
            >

                <span>
                    Sign in
                </span>

                <span class="login-submit-arrow" aria-hidden="true">
                    →
                </span>

            </button>


            {{-- HELP --}}
            <div class="login-help">

                <div class="login-help-divider">
                    <span>
                        Need help?
                    </span>
                </div>

                <p>
                    For employees, faculty, and staff.
                </p>

                <p>
                    Contact ICTU for account access assistance.
                </p>

            </div>

        </form>

    </div>

</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const password = document.getElementById('password');
    const toggle = document.getElementById('loginPasswordToggle');

    if (!password || !toggle) {
        return;
    }

    toggle.addEventListener('click', function () {
        const showing = password.type === 'text';

        password.type = showing ? 'password' : 'text';

        const label = showing ? 'Show password' : 'Hide password';

        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('title', label);
    });
});
</script>

@endsection