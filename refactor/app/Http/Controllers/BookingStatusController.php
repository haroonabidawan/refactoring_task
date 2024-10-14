<?php

namespace DTApi\Http\Controllers;

use DTApi\Repository\BookingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class BookingStatusController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected BookingRepository $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * Accept Job
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function acceptJob(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $response = $this->repository->acceptJob($request->all(), $request->__authenticatedUser);
            DB::commit();

            return $this->returnJsonResponse(data: $response['list']);
        } catch (\Exception $exception) {
            DB::rollBack();

            return $this->returnJsonResponse(
                message: $exception->getMessage(),
                statusCode: $exception->getCode() == Response::HTTP_BAD_REQUEST ? Response::HTTP_BAD_REQUEST : Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Accept Job via Id
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function acceptJobWithId(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $response = $this->repository->acceptJobWithId($request->get('job_id'), $request->__authenticatedUser);
            DB::commit();
            return $this->returnJsonResponse(
                message: $response['message'],
                data: $response['list']
            );
        } catch (\Exception $exception) {
            DB::rollBack();

            return $this->returnJsonResponse(
                message: $exception->getMessage(),
                statusCode: $exception->getCode() == Response::HTTP_BAD_REQUEST ? Response::HTTP_BAD_REQUEST : Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Cancel Job
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelJob(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $response = $this->repository->cancelJobAjax($request->all(), $request->__authenticatedUser);
            DB::commit();

            return $this->returnJsonResponse(data: $response['list']);
        } catch (\Exception $exception) {
            DB::rollBack();

            return $this->returnJsonResponse(
                message: $exception->getMessage(),
                statusCode: $exception->getCode() == Response::HTTP_BAD_REQUEST ? Response::HTTP_BAD_REQUEST : Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * End/Complete Job
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function endJob(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $response = $this->repository->endJob($request->all());
            DB::commit();

            return $this->returnJsonResponse();
        } catch (\Exception $exception) {
            DB::rollBack();

            return $this->returnJsonResponse(
                message: $exception->getMessage(),
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Customer Not Call
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function customerNotCall(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->repository->customerNotCall($request->all());
            DB::commit();

            return $this->returnJsonResponse();
        } catch (\Exception $exception) {
            DB::rollBack();

            return $this->returnJsonResponse(
                message: $exception->getMessage(),
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Reopen Job
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function reopen(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $response = $this->repository->reopen($request->all());
            DB::commit();

            return $this->returnJsonResponse($response['message']);
        } catch (Exception $exception) {
            DB::rollBack();
            
            return $this->returnJsonResponse(
                message: $exception->getMessage(),
                statusCode: $exception->getCode() == Response::HTTP_BAD_REQUEST ? Response::HTTP_BAD_REQUEST : Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
