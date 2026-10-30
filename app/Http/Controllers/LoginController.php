<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('agent_host.authenticated') === true) {
            return redirect('/');
        }

        $tab = ($request->is('register') || $request->query('tab') === 'signup') ? 'signup' : 'signin';

        return view('login', ['tab' => $tab]);
    }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:45'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $rawUsername = trim((string) ($validated['username'] ?? ''));
        if ($rawUsername === '') {
            $rawUsername = Str::slug($validated['name'], '_');
        }
        if ($rawUsername === '') {
            $rawUsername = Str::before($validated['email'], '@');
        }

        $username = strtolower($rawUsername);
        if (User::query()->whereRaw('LOWER(username) = ?', [$username])->exists()) {
            $username = $username.'_'. random_int(100, 999);
        }

        $user = User::query()->create([
            'name' => trim($validated['name']),
            'username' => $username,
            'email' => strtolower(trim($validated['email'])),
            'phone' => trim($validated['phone']),
            'password' => Hash::make($validated['password']),
        ]);

        $this->authenticateSession($request, $user);

        return redirect()->intended('/');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'login' => ['nullable', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ]);

        $login = strtolower(trim((string) ($request->input('login') ?? $request->input('username') ?? $request->input('email') ?? '')));
        $password = (string) $request->input('password');

        if ($login !== '' && Schema::hasTable('users')) {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$login])
                ->orWhereRaw('LOWER(username) = ?', [$login])
                ->orWhereRaw('LOWER(name) = ?', [$login])
                ->first();

            if ($user && Hash::check($password, $user->password)) {
                $this->authenticateSession($request, $user);

                return redirect()->intended('/');
            }
        }

        $hash = (string) config('agenthost.password_hash');

        if ($hash === '' && $login === '') {
            return back()->withErrors(['password' => 'No password is configured on the server (AGENT_HOST_PASSWORD_HASH).']);
        }

        if ($hash !== '' && Hash::check($password, $hash)) {
            $request->session()->regenerate();
            $request->session()->put('agent_host.authenticated', true);
            $request->session()->put('agent_host.user', [
                'id' => 0,
                'name' => $login !== '' ? $login : 'UCYenyen',
                'username' => $login !== '' ? $login : 'ucyenyen',
                'email' => filter_var($login, FILTER_VALIDATE_EMAIL) ? $login : 'bryankunhahaha@gmail.com',
                'phone' => null,
            ]);

            return redirect()->intended('/');
        }

        return back()
            ->withInput($request->only('login'))
            ->withErrors(['password' => 'Invalid username/email or password.']);
    }

    public function destroy(Request $request): RedirectResponse|Response
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->noContent() : redirect()->route('login');
    }

    protected function authenticateSession(Request $request, User $user): void
    {
        $request->session()->regenerate();
        $request->session()->put('agent_host.authenticated', true);
        $request->session()->put('agent_host.user_id', $user->id);
        $request->session()->put('agent_host.user', [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);
    }
}
