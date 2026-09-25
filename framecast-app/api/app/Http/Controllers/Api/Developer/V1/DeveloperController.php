<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\ProjectCreationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Shared shape for the developer API: one error envelope, one validation
 * envelope, one way to build a project link. Nothing here reads a request's
 * auth — the middleware has already bound the user to one workspace.
 */
abstract class DeveloperController extends Controller
{
    /**
     * Validate, returning the same envelope as every other failure rather
     * than Laravel's default `message`/`errors` shape.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function validated(Request $request, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $e) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                $this->fail('validation_failed', 'The request is invalid.', 422, ['errors' => $e->errors()]),
            );
        }
    }

    /** @param array<string, mixed> $context */
    protected function fail(string $code, string $message, int $status, array $context = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($context !== []) {
            $error['context'] = $context;
        }

        return response()->json(['error' => $error], $status);
    }

    protected function creationFailed(ProjectCreationException $e): JsonResponse
    {
        return $this->fail($e->errorCode, $e->getMessage(), $e->status, $e->context);
    }

    protected function projectUrl(Project $project): string
    {
        return rtrim((string) config('app.frontend_url', 'https://app.wyvstudio.com'), '/').'/projects/'.$project->getKey().'/editor';
    }
}
