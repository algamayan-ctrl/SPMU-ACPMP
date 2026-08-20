<?php

namespace App\Http\Middleware;

use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveWorkspace
{
    public function handle(Request $request, Closure $next, string ...$workspaces): Response
    {
        $user = $request->user();
        abort_unless($user, 403);

        $primary = $user->primaryWorkspace();
        abort_unless($primary, 403, 'This account has no valid portal assignment.');

        if ($request->session()->get('active_workspace') !== $primary) {
            $request->session()->put('active_workspace', $primary);
        }

        $required = collect($workspaces)->map(fn ($workspace) => strtoupper($workspace));
        if ($required->contains($primary)) {
            return $next($request);
        }

        $delegatedWorkspace = $this->delegatedApprovalWorkspace($request, $required->all());
        abort_unless($delegatedWorkspace, 403);
        $request->attributes->set('delegated_workspace', $delegatedWorkspace);

        return $next($request);
    }

    /** @param list<string> $required */
    private function delegatedApprovalWorkspace(Request $request, array $required): ?string
    {
        $routeName = $request->route()?->getName();
        if (! in_array($routeName, [
            'approvals.index',
            'approvals.decide',
            'requests.show',
        ], true)) {
            return null;
        }

        $delegated = collect($request->user()->delegatedApprovalWorkspaces())
            ->intersect($required)
            ->values();
        $borrowingRequest = $request->route('borrowingRequest');

        if ($borrowingRequest instanceof BorrowingRequest) {
            $stage = match ($borrowingRequest->status) {
                RequestStatus::UnderSpmu => 'SPMU',

                /*
                 * Only SPMU is an active delegation target in the current workflow.
                 */
                default => null,
            };

            return $stage && $delegated->contains($stage) ? $stage : null;
        }

        return $delegated->count() === 1 ? $delegated->first() : null;
    }
}
