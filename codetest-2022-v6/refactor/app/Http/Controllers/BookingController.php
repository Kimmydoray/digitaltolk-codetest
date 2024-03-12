<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    /**
     * The booking repository instance.
     *
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     *
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * Get all bookings or user's bookings based on request parameters.
     *
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        if ($user_id = $request->get('user_id')) {
            // Get bookings for a specific user
            $response = $this->repository->getUsersJobs($user_id);
        } elseif ($request->__authenticatedUser->user_type == env('ADMIN_ROLE_ID') || $request->__authenticatedUser->user_type == env('SUPERADMIN_ROLE_ID')) {
            // Get all bookings for admin or superadmin
            $response = $this->repository->getAll($request);
        }

        return response($response);
    }

    /**
     * Get a specific booking by ID.
     *
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        // Retrieve the booking with translator and user details
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return response($job);
    }

    /**
     * Store a newly created booking in storage.
     *
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $data = $request->all();
        // Store the booking and return the response
        $response = $this->repository->store($request->__authenticatedUser, $data);

        return response($response);
    }

    /**
     * Update the specified booking in storage.
     *
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $data = $request->all();
        $cuser = $request->__authenticatedUser;
        // Update the booking and return the response
        $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);

        return response($response);
    }

    /**
     * Store an immediate job email in storage.
     *
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $adminSenderEmail = config('app.adminemail');
        $data = $request->all();
        // Store the immediate job email and return the response
        $response = $this->repository->storeJobEmail($data);

        return response($response);
    }

        /**
     * Get booking history for a user.
     *
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        if ($user_id = $request->get('user_id')) {
            // Get booking history for a specific user
            $response = $this->repository->getUsersJobsHistory($user_id, $request);
            return response($response);
        }

        return null;
    }

    /**
     * Accept a job by the user.
     *
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        // Accept the job and return the response
        $response = $this->repository->acceptJob($data, $user);

        return response($response);
    }

    /**
     * Accept a job with a specific ID.
     *
     * @param Request $request
     * @return mixed
     */
    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = $request->__authenticatedUser;

        // Accept the job with the specified ID and return the response
        $response = $this->repository->acceptJobWithId($data, $user);

        return response($response);
    }

        /**
     * Cancel a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        // Cancel the job and return the response
        $response = $this->repository->cancelJobAjax($data, $user);

        return response($response);
    }

    /**
     * End a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();

        // End the job and return the response
        $response = $this->repository->endJob($data);

        return response($response);
    }

    /**
     * Mark a job as "customer not call".
     *
     * @param Request $request
     * @return mixed
     */
    public function customerNotCall(Request $request)
    {
        $data = $request->all();

        // Mark the job as "customer not call" and return the response
        $response = $this->repository->customerNotCall($data);

        return response($response);
    }

        /**
     * Get potential jobs for a user.
     *
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        // Get potential jobs for the user and return the response
        $response = $this->repository->getPotentialJobs($user);

        return response($response);
    }

    /**
     * Update distance feed for a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function distanceFeed(Request $request)
    {
        $data = $request->all();

        // Extract necessary data from the request
        $distance = isset($data['distance']) ? $data['distance'] : '';
        $time = isset($data['time']) ? $data['time'] : '';
        $jobid = isset($data['jobid']) ? $data['jobid'] : '';
        $session = isset($data['session_time']) ? $data['session_time'] : '';

        // Determine if the job is flagged or manually handled
        $flagged = $data['flagged'] == 'true' ? 'yes' : 'no';
        $manually_handled = $data['manually_handled'] == 'true' ? 'yes' : 'no';
        $by_admin = $data['by_admin'] == 'true' ? 'yes' : 'no';
        $admincomment = isset($data['admincomment']) ? $data['admincomment'] : '';

        // Update distance and time for the job if provided
        if ($time || $distance) {
            $affectedRows = Distance::where('job_id', '=', $jobid)->update(array('distance' => $distance, 'time' => $time));
        }

        // Update additional job information if provided
        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            $affectedRows1 = Job::where('id', '=', $jobid)->update(array('admin_comments' => $admincomment, 'flagged' => $flagged, 'session_time' => $session, 'manually_handled' => $manually_handled, 'by_admin' => $by_admin));
        }

        return response('Record updated!');
    }

        /**
     * Reopen a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function reopen(Request $request)
    {
        $data = $request->all();

        // Reopen the job and return the response
        $response = $this->repository->reopen($data);

        return response($response);
    }

    /**
     * Resend notifications for a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return response(['success' => 'Push sent']);
    }

    /**
     * Resend SMS notifications for a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

}