<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function choose(Request $request): View|RedirectResponse
    {
        $workspaces = $request->user()->allowedWorkspaces();
        if (count($workspaces) === 1) {
            $request->session()->put('active_workspace', $workspaces[0]);

            return redirect()->route('dashboard');
        }

        return view('workspace.choose', compact('workspaces'));
    }

    public function select(Request $request): RedirectResponse
    {
        $workspaces = $request->user()->allowedWorkspaces();
        $data = $request->validate(['workspace' => ['required', Rule::in($workspaces)]]);
        $request->session()->put('active_workspace', $data['workspace']);

        return redirect()->route('dashboard')->with('status', $data['workspace'].' workspace activated.');
    }
}
