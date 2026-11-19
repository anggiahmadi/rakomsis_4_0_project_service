<?php

use App\Traits\ApiResponser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.access' => \App\Http\Middleware\AuthenticateAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        if ($exceptions instanceof HttpException) {

            $code = $exceptions->getStatusCode();

            $message = Response::$statusTexts[$code];

            return ApiResponser::staticErrorResponse($message, $code);
        }

        if ($exceptions instanceof ModelNotFoundException) {
            $model = strtolower(class_basename($exceptions->getModel()));

            return ApiResponser::staticErrorResponse("Does not exist any instance of {$model} with given id", Response::HTTP_NOT_FOUND);
        }

        if ($exceptions instanceof AuthorizationException) {
            return ApiResponser::staticErrorResponse($exceptions->getMessage(), Response::HTTP_FORBIDDEN);
        }

        if ($exceptions instanceof AuthenticationException) {
            return ApiResponser::staticErrorResponse($exceptions->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        if ($exceptions instanceof ValidationException) {
            $errors = $exceptions->validator->errors()->getMessages();

            return ApiResponser::staticErrorResponse($errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ApiResponser::staticErrorResponse('Unexpected error. Try later', Response::HTTP_INTERNAL_SERVER_ERROR);
    })->create();
