<?php

namespace DTApi\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesResources;
use Symfony\Component\HttpFoundation\Response;

class Controller extends BaseController
{
    use AuthorizesRequests, AuthorizesResources, DispatchesJobs, ValidatesRequests;

    /**
     * @param string $message
     * @param mixed|null $data
     * @param int $statusCode
     *
     * @return JsonResponse
     */
    public function returnJsonResponse(
        string $message = "Request Processed Successfully",
        mixed $data = null,
        int $statusCode = Response::HTTP_OK
    ): JsonResponse
    {
        return response()->json([
            "message" => $message,
            "data" => $data
        ], $statusCode);
    }
}
