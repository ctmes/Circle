<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Routing\Controller;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => strtolower($data['email']),
            'password' => $data['password'],
        ]);

        return response()->json([
            'user'  => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'token' => $user->createToken('api')->plainTextToken,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        // One generic message for both branches — distinguishing them tells an
        // attacker which addresses are registered.
        if ($user === null || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        return response()->json([
            'user'  => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    /**
     * Ask for a reset link.
     *
     * Always answers the same way, whether or not the address is registered.
     * Login already refuses to distinguish a wrong password from an unknown
     * account for that reason, and a reset endpoint that says "no such user" is
     * the same disclosure with a friendlier face — it turns this form into a
     * way to test whether somebody's client is on the platform.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        Password::sendResetLink(['email' => Str::lower($data['email'])]);

        return response()->json([
            'message' => 'If that address has an account, a reset link is on its way.',
        ]);
    }

    /**
     * Redeem the link.
     *
     * Every existing API token is destroyed on success. Somebody resetting a
     * password is either locked out or compromised, and in the second case
     * leaving the attacker's bearer token alive would make the reset
     * ceremonial.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $status = Password::reset(
            [
                'email'                 => Str::lower($data['email']),
                'password'              => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token'                 => $data['token'],
            ],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'This reset link is no longer valid. Ask for a new one.',
            ]);
        }

        $user = User::where('email', Str::lower($data['email']))->firstOrFail();

        return response()->json([
            'user'  => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
        ]);
    }
}
