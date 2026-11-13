<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->only('id', 'name', 'phone', 'email', 'preferred_locale', 'role')]);
    }

    public function update(Request $request): JsonResponse
    {
        // `email` and `phone` are login identities. Letting a user set them here,
        // unverified, would let them squat on someone else's address and receive
        // that person's account when they first sign in.
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'preferred_locale' => ['sometimes', 'in:ar,en'],
        ]);

        $request->user()->update($data);

        return response()->json(['data' => $request->user()->only('id', 'name', 'phone', 'email', 'preferred_locale')]);
    }
}
