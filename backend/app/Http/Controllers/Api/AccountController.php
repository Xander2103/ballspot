<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    public function delete(Request $request): JsonResponse
    {
        $user = $request->user();
        $id   = $user->id;

        // Revoke all tokens first so the current token is immediately invalid
        $user->tokens()->delete();

        // Anonymize personal fields — preserves row so foreign key refs stay intact
        $user->update([
            'name'     => 'Deleted User',
            'email'    => "deleted-{$id}@ballspot.deleted",
            'username' => "deleted-{$id}",
            'password' => Hash::make(Str::random(32)),
        ]);

        return response()->json(['message' => 'Your account has been deleted.']);
    }
}
