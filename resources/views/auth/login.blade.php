@extends('layouts.app', ['title' => 'Sign in'])

@section('content')
<section class="auth-shell auth-simple">
    <div class="auth-card">
        <div class="auth-heading">
            <span class="auth-mark" aria-hidden="true">SP</span>
            <div><p class="eyebrow">CSPC property borrowing</p><h1>Welcome to SPMU-ACPMP</h1></div>
        </div>
        <p class="auth-help">Sign in using the CSPC account registered by ICTU.</p>
        <form class="form-card" method="post" action="{{ route('login.store') }}">
            @csrf
            <label for="email">CSPC email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" placeholder="name@cspc.edu.ph" required autofocus>
            @error('email')<p class="field-error">{{ $message }}</p>@enderror

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
            @error('password')<p class="field-error">{{ $message }}</p>@enderror

            <label class="checkbox"><input name="remember" type="checkbox" value="1"> Keep me signed in on this device</label>
            <button class="button primary full" type="submit">Sign in</button>
        </form>
        <p class="auth-note">Employees, faculty, and staff only. Contact ICTU if your account cannot sign in.</p>
    </div>
</section>
@endsection
