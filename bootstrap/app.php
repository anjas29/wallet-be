<?php

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    $e->getMessage(),
                    $e->status,
                    $e->errors(),
                ),
                $e instanceof AuthenticationException => ApiResponse::error(
                    'Unauthenticated.',
                    401,
                ),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    'Resource not found.',
                    404,
                ),
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => ApiResponse::error(
                    'This action is unauthorized.',
                    403,
                ),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() !== '' ? $e->getMessage() : 'HTTP error.',
                    $e->getStatusCode(),
                ),
                default => ApiResponse::error(
                    config('app.debug') ? $e->getMessage() : 'Server error.',
                    500,
                ),
            };
        });
    })->create();
