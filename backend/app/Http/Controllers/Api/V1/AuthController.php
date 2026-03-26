<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\CaptchaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private readonly CaptchaService $captchaService)
    {
    }

    public function captcha(): JsonResponse
    {
        return response()->json($this->captchaService->generate());
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'captcha_key' => ['required', 'string'],
            'captcha_code' => ['required', 'string', 'max:16'],
        ]);

        $isCaptchaValid = $this->captchaService->verify(
            $credentials['captcha_key'],
            $credentials['captcha_code']
        );
        if (! $isCaptchaValid) {
            return response()->json(['message' => '验证码错误或已过期'], 422);
        }

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => '邮箱或密码错误'], 422);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => '账号已禁用，请联系管理员'], 403);
        }

        $token = $user->createToken(
            'taskroute-'.now()->format('YmdHis'),
            [$user->role]
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => '已退出登录']);
    }
}
