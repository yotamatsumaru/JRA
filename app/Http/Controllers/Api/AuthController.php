<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Flutterアプリ用 APIログイン/ログアウト
 *
 * 既存のBlade画面向けセッション認証(AuthenticatedSessionController)とは
 * 完全に独立しており、こちらは Laravel Sanctum のトークン認証を使う。
 */
class AuthController extends Controller
{
    /**
     * POST /api/login
     * body: { email, password, device_name? }
     * => { token, user: { id, name, email } }
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'       => ['required', 'string', 'email'],
            'password'    => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['メールアドレスまたはパスワードが正しくありません。'],
            ]);
        }

        $deviceName = $validated['device_name'] ?? $request->userAgent() ?? 'flutter-app';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'ok'    => true,
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * POST /api/logout
     * 現在使用中のトークンのみを失効させる(他デバイスのログインは維持)
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/user
     * ログイン中ユーザー情報の確認(アプリ起動時のトークン検証用)
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'ok'   => true,
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
