<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Credenciais incorretas.']);
        }

        return response()->json(['token' => $user->createToken('intermedia', ['*'], now()->addHour())->plainTextToken]);
    }

    // Compatibilidade com a resposta original dos tutoriais.
    public function loginLegacy(Request $request)
    {
        $response = $this->login($request);
        $data = $response->getData(true);
        $user = User::where('email', $request->input('email'))->firstOrFail();

        return response()->json([
            'user' => $user->only('id', 'name', 'email'),
            'token' => $data['token'],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->only('id', 'name', 'email'));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
