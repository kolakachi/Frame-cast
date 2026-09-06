<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'auth.jwt' => \App\Http\Middleware\AuthenticateWithJwt::class,
            'admin' => \App\Http\Middleware\RequireAdmin::class,
            'admin.ip' => \App\Http\Middleware\AdminIpAllowlist::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
            'broadcasting/auth',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        \Sentry\Laravel\Integration::handles($exceptions);

        $exceptions->render(function (\Illuminate\Validation\ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Logged because a 422 is invisible otherwise: nginx access logs
            // rotate on every deploy, and a customer reporting "The given data
            // was invalid" carries no field, no route and no workspace. The
            // failing keys and rule messages are recorded; request VALUES are
            // not, since they carry prompts and other customer content.
            \Illuminate\Support\Facades\Log::warning('Validation failed', [
                'route'        => $request->path(),
                'method'       => $request->method(),
                'workspace_id' => $request->user()?->workspace_id,
                'user_id'      => $request->user()?->getKey(),
                'fields'       => $exception->errors(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'The given data was invalid.',
                    'details' => $exception->errors(),
                ],
            ], 422);
        });

        // Ensure all API errors return JSON, never HTML
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                $e instanceof \Illuminate\Auth\AuthenticationException      => 401,
                $e instanceof \Illuminate\Auth\Access\AuthorizationException => 403,
                $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException => 404,
                $e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException => 405,
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException => $e->getStatusCode(),
                default => 500,
            };

            $message = $status < 500
                ? $e->getMessage() ?: 'An error occurred.'
                : 'An unexpected error occurred. Please try again.';

            return response()->json([
                'error' => ['code' => 'server_error', 'message' => $message],
            ], $status);
        });
    })->create();
