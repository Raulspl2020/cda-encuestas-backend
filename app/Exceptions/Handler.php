<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        $isDebug = (bool) config('app.debug');

        if ($e instanceof ValidationException) {
            return new JsonResponse([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed.',
                ],
                'errors' => $e->errors(),
            ], $e->status);
        }

        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        $message = $status === 404 ? 'Resource not found.' : 'Internal server error.';

        return new JsonResponse([
            'error' => [
                'code' => $status === 404 ? 'NOT_FOUND' : 'INTERNAL_SERVER_ERROR',
                'message' => $isDebug ? $e->getMessage() : $message,
            ],
        ], $status);
    }
}
