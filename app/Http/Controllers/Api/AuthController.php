<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:20', 'regex:/^[a-zA-Z0-9_]+$/', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'username' => Str::lower($data['username']),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
        ]);

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('easesplit-mobile')->plainTextToken,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $username = Str::lower($data['username']);
        $throttleKey = $username.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'username' => "Too many login attempts. Try again in {$seconds} seconds.",
            ]);
        }

        if (! Auth::attempt(['username' => $username, 'password' => $data['password']])) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'username' => 'The provided credentials are incorrect.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('easesplit-mobile')->plainTextToken,
        ]);
    }

    /**
     * Verifies a Google ID token against Google's tokeninfo endpoint and
     * logs the user in (creating an account on first sign-in).
     */
    public function google(Request $request)
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $data['id_token'],
        ]);

        if ($response->failed()) {
            throw ValidationException::withMessages(['id_token' => 'Invalid Google token.']);
        }

        $payload = $response->json();

        $expectedAudience = config('services.google.client_id');
        if ($expectedAudience && ($payload['aud'] ?? null) !== $expectedAudience) {
            throw ValidationException::withMessages(['id_token' => 'Token was not issued for this app.']);
        }

        if (empty($payload['email']) || ($payload['email_verified'] ?? 'false') !== 'true') {
            throw ValidationException::withMessages(['id_token' => 'Google email is not verified.']);
        }

        $user = User::where('google_id', $payload['sub'])->first();

        if (! $user) {
            $user = User::create([
                'name' => $payload['name'] ?? $payload['email'],
                'username' => $this->generateUniqueUsername($payload['email']),
                'email' => $payload['email'],
                'email_verified_at' => now(),
                'avatar' => $payload['picture'] ?? null,
                'google_id' => $payload['sub'],
                'password' => Hash::make(Str::random(32)),
            ]);
        } else {
            $user->forceFill([
                'name' => $payload['name'] ?? $user->name,
                'email' => $payload['email'],
                'email_verified_at' => now(),
                'avatar' => $payload['picture'] ?? $user->avatar,
            ])->save();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('easesplit-mobile')->plainTextToken,
        ]);
    }

    public function requestOtp(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $throttleKey = 'otp|'.$data['phone'];
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json(['message' => "Too many OTP requests. Try again in {$seconds}s."], 429);
        }
        RateLimiter::hit($throttleKey, 300);

        $otp = (string) random_int(100000, 999999);

        $user = User::firstOrCreate(
            ['phone' => $data['phone']],
            [
                'name' => 'User '.substr($data['phone'], -4),
                'username' => $this->generateUniqueUsername('user'.substr($data['phone'], -6)),
                'password' => Hash::make(Str::random(32)),
            ]
        );

        $user->forceFill([
            'otp_code' => Hash::make($otp),
            'otp_expires_at' => now()->addMinutes(5),
        ])->save();

        // TODO: dispatch to an SMS provider (e.g. MSG91/Twilio) instead of logging.
        logger()->info("Easesplit OTP for {$data['phone']}: {$otp}");

        return response()->json(['message' => 'OTP sent.']);
    }

    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'otp' => ['required', 'string'],
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if (! $user || ! $user->otp_code || ! $user->otp_expires_at?->isFuture() || ! Hash::check($data['otp'], $user->otp_code)) {
            throw ValidationException::withMessages(['otp' => 'Invalid or expired OTP.']);
        }

        $user->forceFill([
            'otp_code' => null,
            'otp_expires_at' => null,
            'phone_verified_at' => now(),
            'last_login_at' => now(),
        ])->save();

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('easesplit-mobile')->plainTextToken,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Derive a unique, valid username from an arbitrary seed string (an
     * email local-part, a phone-derived string, etc.) for auth flows
     * (Google, OTP) that don't collect a username from the user directly.
     */
    private function generateUniqueUsername(string $seed): string
    {
        $base = Str::of($seed)->before('@')->lower()->replaceMatches('/[^a-z0-9_]/', '')->substr(0, 15)->value();
        $base = $base !== '' ? $base : 'user';

        $username = $base;
        $suffix = 0;

        while (User::where('username', $username)->exists()) {
            $suffix++;
            $username = $base.$suffix;
        }

        return $username;
    }
}
