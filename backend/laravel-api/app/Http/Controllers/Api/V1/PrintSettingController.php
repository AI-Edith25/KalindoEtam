<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PrintSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service (index/update) needs only auth:sanctum — every logged-in user manages their own
 * print preferences, same trust level as the localStorage they already fully control. Admin
 * actions (showForUser/updateForUser) are separately permission-gated in routes/api.php.
 */
class PrintSettingController extends Controller
{
    use ApiResponse;

    public function __construct(protected PrintSettingService $printSettingService) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success($this->printSettingService->forUser($request->user()->id));
    }

    public function update(Request $request, string $documentType): JsonResponse
    {
        $setting = $this->printSettingService->save($request->user()->id, $documentType, $request->all(), $request->user()->id);

        return $this->success($setting->settings, 'Print settings saved.');
    }

    public function showForUser(User $user): JsonResponse
    {
        return $this->success($this->printSettingService->forUser($user->id));
    }

    public function updateForUser(Request $request, User $user, string $documentType): JsonResponse
    {
        $setting = $this->printSettingService->save($user->id, $documentType, $request->all(), $request->user()->id);

        return $this->success($setting->settings, 'Print settings saved.');
    }
}
