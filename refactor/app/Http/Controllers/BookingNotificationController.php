<?php

namespace DTApi\Http\Controllers;

use DTApi\Repository\BookingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class BookingNotificationController extends Controller
{
    /**
     * @var BookingRepository
     */
    protected BookingRepository $repository;

    /**
     * BookingNotificationController constructor.
     *
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * Send Notification
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resendNotifications(Request $request): JsonResponse
    {
        try {
            $job = $this->repository->find($request->jobid);
            if (!$job) {
                throw new \Exception('Job not found', Response::HTTP_NOT_FOUND);
            }
            $job_data = $this->repository->jobToData($job);
            $this->repository->sendNotificationTranslator($job, $job_data, '*');

            return $this->returnJsonResponse("Push Sent");
        } catch (\Exception $exception) {

            return $this->returnJsonResponse(
                message: $exception->getCode() == Response::HTTP_NOT_FOUND ? $exception->getMessage() : "Server Error",
                statusCode: $exception->getCode() == Response::HTTP_NOT_FOUND ? $exception->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Sends SMS to Translator
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resendSMSNotifications(Request $request): JsonResponse
    {
        try {
            $job = $this->repository->find($request->jobid);
            if (!$job) {
                throw new \Exception('Job not found', Response::HTTP_NOT_FOUND);
            }
            // this need to be checked
            $this->repository->sendSMSNotificationToTranslator($job);
            return $this->returnJsonResponse("Push Sent");
        } catch (\Exception $exception) {
            return $this->returnJsonResponse(
                message: $exception->getCode() == Response::HTTP_NOT_FOUND ? $exception->getMessage() : "Server Error",
                statusCode: $exception->getCode() == Response::HTTP_NOT_FOUND ? $exception->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Send Immediate Job Mail
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function immediateJobEmail(Request $request): JsonResponse
    {
        $data = $request->all();
        $validationRules = [
            'user_email_job_id' => 'required|exists:jobs,id',
            'user_email' => 'nullable|email',
            'reference' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'instructions' => 'nullable|string|max:1000',
            'town' => 'nullable|string|max:255',
            'user_type' => 'required|string|in:translator,client',
        ];
        try {
            $this->repository->validate($data, $validationRules);
            $response = $this->repository->storeJobEmail($request->all());

            return $this->returnJsonResponse(data: collect($response)->except(['status', 'message'])->all());
        } catch (ValidationException $e) {
            return $this->returnJsonResponse(
                message: 'Validation error',
                data: ['errors' => $e->errors()],
                statusCode: Response::HTTP_BAD_REQUEST
            );
        } catch (\Exception $e) {
            return $this->returnJsonResponse(
                message: 'An error occurred while updating the job',
                data: ['error' => $e->getMessage()],
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}