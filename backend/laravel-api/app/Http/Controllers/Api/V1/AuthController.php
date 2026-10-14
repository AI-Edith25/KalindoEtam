<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuthService $authService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated('email'), $request->validated('password'));

        return $this->success([
            'token' => $result['token'],
            'user' => new UserResource($result['user']),
        ], 'Login successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->success(null, 'Logged out.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(new UserResource($request->user()->load('roles')));
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->authService->changePassword($request->user(), $request->validated('current_password'), $request->validated('password'));

        return $this->success(null, 'Password changed. Please log in again.');
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $this->authService->register($request->validated());

        return $this->success(null, 'Registrasi berhasil. Akun Anda menunggu persetujuan Admin.');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->forgotPassword($request->validated('email'));

        return $this->success(null, 'Jika email terdaftar, link reset password telah dikirim.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword($request->validated('email'), $request->validated('token'), $request->validated('password'));

        return $this->success(null, 'Password berhasil diubah. Silakan login.');
    }
}
