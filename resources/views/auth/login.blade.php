@extends('layouts.app', ['title' => 'Sign in'])

@section('content')
<section class="auth-shell auth-simple">
    <div class="auth-card">
        <div class="auth-heading">
            <div><h1>Sign in to SPMU-ACPMP</h1><p>Use your registered CSPC account.</p></div>
        </div>
        <form class="form-card" method="post" action="{{ route('login.store') }}">
            @csrf
            <label for="email">CSPC email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" placeholder="name@cspc.edu.ph" required autofocus>
            @error('email')<p class="field-error">{{ $message }}</p>@enderror

            <div class="password-field">
                <label for="password">Password</label>
                <button type="button" class="password-toggle" data-toggle-password aria-label="Show password" title="Show password">
                    <span>Show</span>
                </button>
            </div>
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
            @error('password')<p class="field-error">{{ $message }}</p>@enderror

            <label class="checkbox"><input name="remember" type="checkbox" value="1"> Keep me signed in</label>
            <button class="button primary full" type="submit">Sign in</button>
        </form>
        <p class="auth-note">For employees, faculty, and staff. Contact ICTU for account access assistance.</p>
    </div>
</section>
@endsection
