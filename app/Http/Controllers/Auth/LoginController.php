<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $credentials['account_status'] = AccountStatus::Active->value;

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'The supplied credentials are invalid or the account is not active.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();
        $user = $request->user();
        $classification = AccessClassification::tryFrom(
            strtoupper((string) $user?->getRawOriginal('access_classification'))
        );

        if (! $classification?->isPortalEnabled()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'email' => 'This account uses a retired or invalid system role. Contact ICTU.',
            ])->onlyInput('email');
        }

        $workspace = $user->primaryWorkspace();

        if (! $workspace) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'email' => 'This account has no valid portal assignment. Contact ICTU.',
            ])->onlyInput('email');
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $request->session()->put('active_workspace', $workspace);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
