<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserHasRole;
use App\Providers\ApiServiceProvider;
use App\Support\ApiMessages;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    // Added to the providers of bootstrap/providers.php, which is left untouched.
    ->withProviders([
        ApiServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Unauthenticated /api requests are never redirected to a web login page (none exists):
        // the AuthenticationException handler below answers with a JSON 401.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson() ? null : route('login')
        );

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'active' => EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api request gets JSON errors, even without an "Accept: application/json" header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // One error format for the whole API: { "message": "..." } (+ "errors" for validation).
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => ApiMessages::INVALID_DATA,
                'errors' => $e->errors(),
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => ApiMessages::UNAUTHENTICATED], 401);
        });

        // 403, 404, 405, 429... (also the converted AuthorizationException and ModelNotFoundException).
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();

            $message = match (true) {
                $status === 401 => ApiMessages::UNAUTHENTICATED,
                $status === 403 => ApiMessages::FORBIDDEN,
                $status === 404 => ApiMessages::NOT_FOUND,
                $status === 405 => ApiMessages::METHOD_NOT_ALLOWED,
                $status === 429 => ApiMessages::TOO_MANY_REQUESTS,
                $status >= 500 => ApiMessages::SERVER_ERROR,
                default => ApiMessages::BAD_REQUEST,
            };

            return response()->json(['message' => $message], $status, $e->getHeaders());
        });

        // Anything else (SQL error, bug...): never leak internals, even with APP_DEBUG=true.
        // The exception is still reported to the logs.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')
                || $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof HttpResponseException
                || $e instanceof HttpExceptionInterface) {
                return null;
            }

            return response()->json(['message' => ApiMessages::SERVER_ERROR], 500);
        });
    })->create();
