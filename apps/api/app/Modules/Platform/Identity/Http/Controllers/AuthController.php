<?php

declare(strict_types=1);

namespace App\Modules\Platform\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Platform\Identity\Actions\ProvisionTenant;
use App\Shared\Enums\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Session-based auth for the two SPAs via Sanctum. Both dashboards are
 * first-party origins, so cookies are the right mechanism — no token storage
 * in localStorage, and CSRF protection comes for free.
 */
final class AuthController
{
    public function register(Request $request, ProvisionTenant $provision): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'workspace' => ['required', 'string', 'max:255'],
            'product' => ['required', 'string', 'in:fashion,courses,beauty,autoparts'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $tenant = $provision->handle(
            $user,
            $validated['workspace'],
            Product::from($validated['product']),
        );

        Auth::login($user);
        $this->regenerateSession($request);

        return response()->json([
            'user' => $this->userPayload($user),
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        // One generic failure for both branches: distinguishing "no such user"
        // from "wrong password" turns the endpoint into an account enumerator.
        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $this->regenerateSession($request);

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /**
     * Only browser requests from a stateful domain carry a session. A
     * token-based client reaching these endpoints should not get a 500 for
     * the absence of one.
     */
    private function regenerateSession(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        // Rotates the session id on privilege change, which is what closes
        // session fixation.
        $request->session()->regenerate();
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            // Coerced rather than passed through: a freshly created user has
            // not been read back from the database, so the column default has
            // not populated the attribute and the cast yields null. The
            // frontends branch on this, and null is not false.
            'is_platform_admin' => (bool) $user->is_platform_admin,
            'active_tenant_id' => $user->active_tenant_id,
            'tenants' => $user->tenants()->get()->map(fn ($t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'product' => $t->product->value,
                'role' => $t->pivot->role,
            ]),
        ];
    }
}
