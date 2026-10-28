<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        return $request->session()->get('agent_host.authenticated') === true ? redirect('/') : view('login');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $hash = (string) config('agenthost.password_hash');

        if ($hash === '') {
            return back()->withErrors(['password' => 'No password is configured on the server (AGENT_HOST_PASSWORD_HASH).']);
        }

        if (! Hash::check($request->input('password'), $hash)) {
            return back()->withErrors(['password' => 'Wrong password.']);
        }

        $request->session()->regenerate();
        $request->session()->put('agent_host.authenticated', true);

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse|Response
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->noContent() : redirect()->route('login');
    }
}
