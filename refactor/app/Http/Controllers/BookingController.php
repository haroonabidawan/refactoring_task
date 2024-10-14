<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
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
     * Get List of Jobs
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->get('user_id');
        if ($userId) {
            $jobs = $this->repository->getUsersJobs($userId);
        } else {
            $isAdminOrSuperAdmin = in_array($request->__authenticatedUser->user_type, [
                env('ADMIN_ROLE_ID'),
                env('SUPERADMIN_ROLE_ID')
            ]);
            if ($isAdminOrSuperAdmin) {
                $jobs = $this->repository->getAll($request);
            }
        }

        return $this->returnJsonResponse(data: [$jobs ?? []]);
    }

    /**
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);
        if (!$job) return $this->returnJsonResponse('Job Not Found', statusCode: Response::HTTP_NOT_FOUND);

        return $this->returnJsonResponse(data: $job);
    }

    /**
     * Create a new Job
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->all();
        $validationRules = [
            'from_language_id' => 'required|exists:languages,id',
            'immediate' => 'required|in:yes,no',
            'due_date' => 'required_if:immediate,no|date',
            'due_time' => 'required_if:immediate,no',
            'customer_phone_type' => 'required_without:customer_physical_type|in:yes,no',
            'customer_physical_type' => 'required_without:customer_phone_type|in:yes,no',
            'duration' => 'required|integer',
            'job_for' => 'required|array'
        ];

        try {
            // Validating Data with base repo methods
            $this->repository->validate($data, $validationRules);
            $response = $this->repository->store($request->__authenticatedUser, $data);

            return $this->returnJsonResponse(
                message: $response['message'],
                data: [collect($response)->except(['status', 'message'])->all()],
                statusCode: $response['status'] == "fail" ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK
            );

        } catch (ValidationException $e) {
            return $this->returnJsonResponse(
                message: 'Validation error',
                data: ['errors' => $e->errors()],
                statusCode: Response::HTTP_BAD_REQUEST
            );
        } catch (\Exception $e) {
            return $this->returnJsonResponse(
                message: 'An error occurred during booking creation',
                data: ['error' => $e->getMessage()],
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Update a Job
     *
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function update($id, Request $request): JsonResponse
    {
        $data = $request->all();
        $validationRules = [
            'due' => 'required|date',
            'from_language_id' => 'required|exists:languages,id',
            'admin_comments' => 'nullable|string',
            'reference' => 'nullable|string',
        ];

        try {
            $this->repository->validate($data, $validationRules);
            $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $request->__authenticatedUser);

            return $this->returnJsonResponse(
                message: "Job Updated Successfully"
            );
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

    /**
     * Get Jobs History
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getHistory(Request $request): JsonResponse
    {
        $history = [];
        if ($request->get('user_id')) {
            $history = $this->repository->getUsersJobsHistory($request->get('user_id'), $request);
        }

        return $this->returnJsonResponse(data: $history);
    }


    /**
     * Get Potential Jobs
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPotentialJobs(Request $request): JsonResponse
    {
        $response = $this->repository->getPotentialJobs($request->__authenticatedUser);

        return $this->returnJsonResponse($response);
    }
}
