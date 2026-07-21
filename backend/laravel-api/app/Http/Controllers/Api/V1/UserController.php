<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignUserRoleRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(protected UserService $userService) {}

    public function index(): JsonResponse
    {
        return $this->success(UserResource::collection($this->userService->list()));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return $this->success(new UserResource($user), 'User created.', 201);
    }

    public function show(User $user): JsonResponse
    {
        return $this->success(new UserResource($user->load('roles')));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->update($user, $request->validated());

        return $this->success(new UserResource($user), 'User updated.');
    }

    public function activate(User $user): JsonResponse
    {
        $user = $this->userService->setActive($user, true);

        return $this->success(new UserResource($user), 'User activated.');
    }

    public function deactivate(User $user): JsonResponse
    {
        $user = $this->userService->setActive($user, false);

        return $this->success(new UserResource($user), 'User deactivated.');
    }

    public function resetPassword(User $user): JsonResponse
    {
        $newPassword = $this->userService->resetPassword($user);

        return $this->success(['password' => $newPassword], 'Password reset. Share this temporary password with the user securely — it will not be shown again.');
    }

    public function assignRole(AssignUserRoleRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->assignRole($user, $request->validated('role'));

        return $this->success(new UserResource($user), 'Role assigned.');
    }
}
