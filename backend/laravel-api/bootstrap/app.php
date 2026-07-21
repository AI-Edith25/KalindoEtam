<?php

use App\Services\AuditLogService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only app: no 'login' route exists to redirect guests to.
        $middleware->redirectGuestsTo(fn () => null);

        // Spatie's permission middleware — see docs/ADMINISTRATION_DESIGN.md §5.
        // Applied per-action to Administration's own routes (routes/api.php) via
        // Route::apiResource(...)->middlewareFor(...); the permission *data*
        // (Role/Permission models, {module}.{action} names) is unchanged.
        $middleware->alias([
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                ], 404);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'data' => null,
                ], 401);
            }
        });

        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->is('api/*')) {
                $requiredPermissions = $e->getRequiredPermissions();
                $module = explode('.', $requiredPermissions[0] ?? 'unknown.unknown')[0];

                app(AuditLogService::class)->record(
                    'permission_denied',
                    $module,
                    "Denied: {$request->method()} {$request->path()}.",
                    ['required_permissions' => $requiredPermissions, 'route' => $request->path()],
                );

                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to perform this action.',
                    'data' => null,
                ], 403);
            }
        });
    })->create();
