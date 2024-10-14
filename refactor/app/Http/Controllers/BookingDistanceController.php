<?php

namespace DTApi\Http\Controllers;

use DTApi\Repository\BookingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class BookingDistanceController extends Controller
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
     * Update Distance
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->all();
        try {
            DB::beginTransaction();
            $jobId = $data['jobid'] ?? null;
            $distance = $data['distance'] ?? '';
            $time = $data['time'] ?? '';
            $adminComment = $data['admincomment'] ?? '';
            $sessionTime = $data['session_time'] ?? '';
            $flagged = ($data['flagged'] === 'true') ? 'yes' : 'no';
            $manuallyHandled = ($data['manually_handled'] === 'true') ? 'yes' : 'no';
            $byAdmin = ($data['by_admin'] === 'true') ? 'yes' : 'no';

            // Validate flagged data
            if ($flagged === 'yes' && empty($adminComment)) {
                throw new \Exception("Please, add a comment", Response::HTTP_BAD_REQUEST);
            }

            // Update Distance table if distance or time is present
            if ($time || $distance) {
                Distance::where('job_id', $jobId)->update(['distance' => $distance, 'time' => $time]);
            }

            // Update the job record if any relevant data is provided
            if ($adminComment || $sessionTime || $flagged || $manuallyHandled || $byAdmin) {
                $this->repository->update($jobId, [
                    'admin_comments' => $adminComment,
                    'flagged' => $flagged,
                    'session_time' => $sessionTime,
                    'manually_handled' => $manuallyHandled,
                    'by_admin' => $byAdmin
                ]);
            }

            DB::commit();
        } catch (\Exception $exception) {
            DB::rollback();
            return $this->returnJsonResponse(
                message: $exception->getMessage(),
                statusCode: $exception->getCode() === Response::HTTP_BAD_REQUEST
                    ? Response::HTTP_BAD_REQUEST
                    : Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->returnJsonResponse('Record updated!');
    }
}