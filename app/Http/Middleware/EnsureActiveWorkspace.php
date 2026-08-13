<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveWorkspace
{
    public function handle(Request $request, Closure $next, string ...$workspaces): Response
    {
        $user = $request->user();
        abort_unless($user, 403);

        $allowed = $user->allowedWorkspaces();
        $active = strtoupper((string) $request->session()->get('active_workspace'));

        if (! $active || ! in_array($active, $allowed, true)) {
            if (count($allowed) === 1) {
                $active = $allowed[0];
                $request->session()->put('active_workspace', $active);
            } else {
                return redirect()->route('workspace.choose');
            }
        }

        abort_unless(collect($workspaces)->map(fn ($workspace) => strtoupper($workspace))->contains($active), 403);

        return $next($request);
    }
}
