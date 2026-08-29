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
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190', 'unique:users,email,'.$request->user()->id],
            'preferred_locale' => ['sometimes', 'in:ar,en'],
        ]);

        $request->user()->update($data);

        return response()->json(['data' => $request->user()->only('id', 'name', 'phone', 'email', 'preferred_locale')]);
    }
}
