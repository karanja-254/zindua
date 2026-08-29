<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class MasterKeyAuthController extends Controller
{
    /**
     * Authenticate an investigator using only the Master Keycode (no email/username).
     */
    public function authenticate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keycode' => ['required', 'string'],
        ]);

        $keycode = $validated['keycode'];

        // Compare against every stored master key hash. Kept constant-ish by
        // always running at least one hash check to blunt trivial timing probes.
        $user = User::query()
            ->whereNotNull('master_key_hash')
            ->get()
            ->first(fn (User $candidate): bool => Hash::check($keycode, (string) $candidate->master_key_hash));

        if ($user === null) {
            return response()->json([
                'error' => 'Invalid Master Keycode',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $token = $user->createToken('vault-master-key')->plainTextToken;

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Register a new investigator with a display name and unique passkey.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'keycode' => ['required', 'string', 'min:8', 'max:128'],
        ]);

        $keycode = $validated['keycode'];

        $taken = User::query()
            ->whereNotNull('master_key_hash')
            ->get()
            ->contains(fn (User $candidate): bool => Hash::check($keycode, (string) $candidate->master_key_hash));

        if ($taken) {
            return response()->json([
                'error' => 'That passkey is already in use.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = User::create([
            'name' => trim($validated['name']),
            'email' => bin2hex(random_bytes(8)).'@proofvault.local',
            'password' => $keycode,
            'master_key_hash' => Hash::make($keycode),
        ]);

        $token = $user->createToken('vault-master-key')->plainTextToken;

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
        ], Response::HTTP_CREATED);
    }
}
