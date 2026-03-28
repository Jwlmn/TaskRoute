<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\CaptchaService;
use App\Services\Auth\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly CaptchaService $captchaService,
        private readonly DataScopeService $dataScopeService,
    )
    {
    }

    public function captcha(): JsonResponse
    {
        return response()->json($this->captchaService->generate());
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'account' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string'],
            'captcha_key' => ['required', 'string'],
            'captcha_code' => ['required', 'string', 'max:16'],
            'client_type' => ['required', Rule::in(['pc', 'mobile'])],
        ]);

        $isCaptchaValid = $this->captchaService->verify(
            $credentials['captcha_key'],
            $credentials['captcha_code']
        );
        if (! $isCaptchaValid) {
            return response()->json(['message' => '验证码错误或已过期'], 422);
        }

        $user = User::query()->where('account', $credentials['account'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => '账号或密码错误'], 422);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => '账号已禁用，请联系管理员'], 403);
        }

        $tokenNamePrefix = 'taskroute-'.$credentials['client_type'].'-';
        $user->tokens()->where('name', 'like', $tokenNamePrefix.'%')->delete();

        $token = $user->createToken(
            $tokenNamePrefix.now()->format('YmdHis'),
            [$user->role]
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->dataScopeService->serializeUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(null, 401);
        }

        return response()->json($this->dataScopeService->serializeUser($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => '已退出登录']);
    }
}
