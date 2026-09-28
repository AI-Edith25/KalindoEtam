<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * One-shot-per-browser-session capability report (see frontend's reportBrowserDiagnostics) — lets
 * us confirm the "old Chrome can't parse oklch()/color-mix()" hypothesis for a stakeholder's
 * device from the server log, without asking them to open devtools. No PII: only feature-support
 * booleans, UA string, DPR, and the computed body font-family.
 */
class BrowserDiagnosticsController extends Controller
{
    use ApiResponse;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_agent' => 'required|string|max:512',
            'supports_oklch' => 'required|boolean',
            'supports_color_mix' => 'required|boolean',
            'device_pixel_ratio' => 'required|numeric',
            'body_font_family' => 'required|string|max:255',
            'page' => 'required|string|max:255',
        ]);

        Log::channel('single')->info('browser-diagnostic', $data);

        return $this->success(null, 'Recorded.');
    }
}
