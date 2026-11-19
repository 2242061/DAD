<?php

namespace App\Http\Controllers;

use App\Services\AccountSecurity;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function verify(EmailVerificationRequest $request, AccountSecurity $security): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->fulfill();
            $security->record($request->user(), $request->user(), 'email.verified');
        }

        return response()->json(['message' => 'Email confirmado com sucesso.']);
    }

    public function send(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'Se o email ainda não estava confirmado, enviámos um novo link.']);
    }
}
