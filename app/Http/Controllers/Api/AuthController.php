<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private TenantService $tenantService)
    {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $tenant = Tenant::create([
                'name' => $request->tenant_name,
            ]);

            $tenant->users()->attach($user->id, ['role' => 'owner']);
            $user->update(['current_tenant_id' => $tenant->id]);

            $token = $user->createToken('auth-token')->plainTextToken;

            return compact('user', 'tenant', 'token');
        });

        return response()->json([
            'message' => 'Registration successful',
            'user' => $data['user'],
            'tenant' => $data['tenant'],
            'token' => $data['token'],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = User::where('email', $request->email)->firstOrFail();
        $user->tokens()->delete();

        $tenant = $this->tenantService->setForUser($user);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'tenant' => $tenant,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        
        if (method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user()->load(['currentTenant', 'tenants']);

        return response()->json([
            'user' => $user,
            'current_tenant' => $user->currentTenant,
        ]);
    }

    public function switchTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $user = $request->user();

        if (!$this->tenantService->switchTenant($user, $tenant)) {
            return response()->json(['message' => 'Access denied to this tenant'], 403);
        }

        return response()->json([
            'message' => 'Tenant switched successfully',
            'tenant' => $tenant,
        ]);
    }
}
