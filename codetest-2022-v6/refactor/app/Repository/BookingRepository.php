<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * BookingRepository constructor.
     *
     * @param Job $model
     * @param MailerInterface $mailer
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Get jobs associated with a user.
     *
     * @param int $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        // Retrieve the current user
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = array();
        $noramlJobs = array();
        
        if ($cuser && $cuser->is('customer')) {
            // If user is a customer, retrieve their pending, assigned, and started jobs
            $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])->orderBy('due', 'asc')->get();
            $usertype = 'customer';
        } elseif ($cuser && $cuser->is('translator')) {
            // If user is a translator, retrieve new jobs for the translator
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
            $usertype = 'translator';
        }
        
        if ($jobs) {
            foreach ($jobs as $jobitem) {
                // Separate emergency and normal jobs
                if ($jobitem->immediate == 'yes') {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $noramlJobs[] = $jobitem;
                }
            }
            // Check user's involvement in each normal job
            $noramlJobs = collect($noramlJobs)->each(function ($item, $key) use ($user_id) {
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }

    /**
     * Get jobs history associated with a user.
     *
     * @param int $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        // Get page number from request, default to 1
        $page = $request->get('page', 1);
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = array();
        $noramlJobs = array();
        
        if ($cuser && $cuser->is('customer')) {
            // If user is a customer, retrieve their completed, withdrawn, and timed out jobs
            $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15);
            $usertype = 'customer';
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => 0, 'pagenum' => 0];
        } elseif ($cuser && $cuser->is('translator')) {
            // If user is a translator, retrieve historic jobs for the translator
            $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $page);
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);

            $usertype = 'translator';

            $jobs = $jobs_ids;
            $noramlJobs = $jobs_ids;
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => $numpages, 'pagenum' => $page];
        }
    }

       /**
     * Store a new job.
     *
     * @param User $user The user creating the job
     * @param array $data The data for the new job
     * @return array The response indicating success or failure of the operation
     */
    public function store(User $user, array $data)
    {
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;
        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
            $cuser = $user;

            // Check if all required fields are filled
            if (!isset($data['from_language_id'])) {
                $response['status'] = 'fail';
                $response['message'] = "Du måste fylla in alla fält";
                $response['field_name'] = "from_language_id";
                return $response;
            }
            if ($data['immediate'] == 'no') {
                if (isset($data['due_date']) && $data['due_date'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_date";
                    return $response;
                }
                if (isset($data['due_time']) && $data['due_time'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_time";
                    return $response;
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste göra ett val här";
                    $response['field_name'] = "customer_phone_type";
                    return $response;
                }
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
            } else {
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
            }
            if (isset($data['customer_phone_type'])) {
                $data['customer_phone_type'] = 'yes';
            } else {
                $data['customer_phone_type'] = 'no';
            }

            if (isset($data['customer_physical_type'])) {
                $data['customer_physical_type'] = 'yes';
                $response['customer_physical_type'] = 'yes';
            } else {
                $data['customer_physical_type'] = 'no';
                $response['customer_physical_type'] = 'no';
            }

            if ($data['immediate'] == 'yes') {
                // Set due date to current time plus 5 minutes
                $due_carbon = Carbon::now()->addMinute($immediatetime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';

            } else {
                // Set due date from input date and time
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                if ($due_carbon->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in past";
                    return $response;
                }
            }
            // Set gender and certification based on job_for array
            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } else if (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }
            if (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            } else if (in_array('certified', $data['job_for'])) {
                $data['certified'] = 'yes';
            } else if (in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'law';
            } else if (in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'health';
            }
            if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                $data['certified'] = 'both';
            } else if (in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'n_law';
            } else if (in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'n_health';
            }
            // Set job_type based on consumer_type
            if ($consumer_type == 'rwsconsumer')
                $data['job_type'] = 'rws';
            else if ($consumer_type == 'ngo')
                $data['job_type'] = 'unpaid';
            else if ($consumer_type == 'paid')
                $data['job_type'] = 'paid';
            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due))
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

            $job = $cuser->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
            $data['job_for'] = array();
            if ($job->gender != null) {
                if ($job->gender == 'male') {
                    $data['job_for'][] = 'Man';
                } else if ($job->gender == 'female') {
                    $data['job_for'][] = 'Kvinna';
                }
            }
            if ($job->certified != null) {
                if ($job->certified == 'both') {
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } else if ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } else {
                    $data['job_for'][] = $job->certified;
                }
            }

            $data['customer_town'] = $cuser->userMeta->city;
            $data['customer_type'] = $cuser->userMeta->customer_type;

            // Fire event for job creation
            Event::fire(new JobWasCreated($job, $data, '*'));
        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }

        return $response;
    }

        /**
     * Store job email and send notification.
     *
     * @param array $data The data for the job email
     * @return array The response indicating success or failure of the operation
     */
    public function storeJobEmail(array $data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail($data['user_email_job_id']);
        $job->user_email = $data['user_email'] ?? null;
        $job->reference = $data['reference'] ?? '';

        // Update job address, instructions, and town if provided
        if (isset($data['address'])) {
            $job->address = $data['address'] ?: $job->user->userMeta->address;
            $job->instructions = $data['instructions'] ?: $job->user->userMeta->instructions;
            $job->town = $data['town'] ?: $job->user->userMeta->city;
        }
        $job->save();

        // Determine email recipient and subject
        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $job->user->name;
        } else {
            $email = $job->user->email;
            $name = $job->user->name;
        }
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

        // Prepare data for email template
        $send_data = [
            'user' => $job->user,
            'job'  => $job
        ];

        // Send email notification
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        // Fire event for job creation
        Event::fire(new JobWasCreated($job, $this->jobToData($job), '*'));

        // Prepare and return response
        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        return $response;
    }

    /**
     * Convert job object to array data.
     *
     * @param Job $job The job object to convert
     * @return array The array representation of the job
     */
    public function jobToData(Job $job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
        ];

        // Extract due date and time from job's due field
        [$due_date, $due_time] = explode(" ", $job->due);
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = [];

        // Map job gender and certification to human-readable strings
        if ($job->gender != null) {
            $data['job_for'][] = $job->gender == 'male' ? 'Man' : 'Kvinna';
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } elseif ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } elseif ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    /**
     * Handle the completion of a job.
     *
     * @param array $post_data
     * @return void
     */
    public function jobEnd($post_data = [])
    {
        $completeddate = now();
        $jobid = $post_data["job_id"];
        $job = Job::with('translatorJobRel')->findOrFail($jobid);
        
        $start = Carbon::parse($job->due);
        $end = Carbon::parse($completeddate);
        $interval = $end->diff($start)->format('%h:%I:%s');
        
        $job->end_at = $completeddate;
        $job->status = 'completed';
        $job->session_time = $interval;

        // Sending email to user
        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_time = explode(':', $interval);
        $session_time = $session_time[0] . ' tim ' . $session_time[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        // Saving job and firing event
        $job->save();
        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $job->translatorJobRel->user_id : $job->user_id));

        // Sending email to translator
        $translatorJobRel = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
        $translator = $translatorJobRel->user;
        $email = $translator->email;
        $name = $translator->name;
        $data['for_text'] = 'lön';
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        // Updating translator job relationship
        $translatorJobRel->completed_at = $completeddate;
        $translatorJobRel->completed_by = $post_data['userid'];
        $translatorJobRel->save();
    }

    /**
     * Get all potential job IDs for a user.
     *
     * @param int $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = 'unpaid';

        if ($translator_type == 'professional') {
            $job_type = 'paid';
        } elseif ($translator_type == 'rwstranslator') {
            $job_type = 'rws';
        } elseif ($translator_type == 'volunteer') {
            $job_type = 'unpaid';
        }

        $languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;

        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $languages, $gender, $translator_level);

        foreach ($job_ids as $key => $job) {
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checktown) {
                unset($job_ids[$key]);
            }
        }

        return TeHelper::convertJobIdsInObjs($job_ids);
    }

    /**
     * Send push notification to suitable translators for a job.
     *
     * This method identifies suitable translators for a job and sends push notifications to them.
     *
     * @param Job $job The job for which notifications are being sent.
     * @param array $data Additional data related to the job.
     * @param int $exclude_user_id The ID of the user to exclude from receiving notifications.
     * @return void
     */
    public function sendNotificationTranslator(Job $job, array $data = [], int $exclude_user_id)
    {
        // Arrays to store translators who will receive immediate and delayed notifications
        $translator_array = [];
        $delayed_translator_array = [];

        // Retrieve all active translators
        $translators = User::where('user_type', '2')
            ->where('status', '1')
            ->where('id', '!=', $exclude_user_id)
            ->get();

        // Iterate over each translator
        foreach ($translators as $translator) {
            // Check if push notifications need to be sent to this translator
            if (!$this->isNeedToSendPush($translator->id)) {
                continue;
            }

            // Check if the translator can accept emergency jobs and if the job is immediate
            $not_get_emergency = TeHelper::getUsermeta($translator->id, 'not_get_emergency');
            if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') {
                continue;
            }

            // Check if the translator is suitable for this job
            $jobs = $this->getPotentialJobIdsWithUserId($translator->id);
            foreach ($jobs as $potentialJob) {
                if ($job->id == $potentialJob->id) {
                    $job_for_translator = Job::assignedToPaticularTranslator($translator->id, $potentialJob->id);
                    if ($job_for_translator == 'SpecificJob') {
                        $job_checker = Job::checkParticularJob($translator->id, $potentialJob);
                        if ($job_checker != 'userCanNotAcceptJob') {
                            if ($this->isNeedToDelayPush($translator->id)) {
                                $delayed_translator_array[] = $translator;
                            } else {
                                $translator_array[] = $translator;
                            }
                        }
                    }
                }
            }
        }

        // Prepare data for push notification
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = $data['immediate'] == 'no' ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'] : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        $msg_text = ["en" => $msg_contents];

        // Log push notification details
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . now()->format('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delayed_translator_array, $msg_text, $data]);

        // Send push notifications to suitable translators
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);
        $this->sendPushNotificationToSpecificUsers($delayed_translator_array, $job->id, $data, $msg_text, true);
    }

    /**
     * Send SMS notification to translators regarding a job.
     *
     * This method sends SMS notifications to translators regarding a job assignment.
     *
     * @param Job $job The job for which notifications are being sent.
     * @return int The number of translators who received the SMS notifications.
     */
    public function sendSMSNotificationToTranslator(Job $job)
    {
        // Retrieve potential translators for the job
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // Prepare message templates
        $date = now()->format('d.m.Y');
        $time = now()->format('H:i');
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;
        $phoneJobMessageTemplate = trans('sms.phone_job', compact('date', 'time', 'duration', 'jobId'));
        $physicalJobMessageTemplate = trans('sms.physical_job', compact('date', 'time', 'city', 'duration', 'jobId'));
        
        // Determine the appropriate message based on job type
        $message = $job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no' ? $physicalJobMessageTemplate : $phoneJobMessageTemplate;

        // Send SMS notifications to translators
        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }
    
    /**
     * Determine if push notification needs to be delayed for the user.
     *
     * This method checks if the push notification needs to be delayed for the specified user.
     *
     * @param int $user_id The ID of the user for whom the check is being performed.
     * @return bool True if push notification needs to be delayed, false otherwise.
     */
    public function isNeedToDelayPush($user_id)
    {
        // Check if it's not nighttime
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }

        // Check if the user has opted out of receiving push notifications at nighttime
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        return $not_get_nighttime == 'yes';
    }

    /**
     * Determine if push notification needs to be sent for the user.
     *
     * This method checks if the push notification needs to be sent for the specified user.
     *
     * @param int $user_id The ID of the user for whom the check is being performed.
     * @return bool True if push notification needs to be sent, false otherwise.
     */
    public function isNeedToSendPush($user_id)
    {
        // Check if the user has opted out of receiving push notifications
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        return $not_get_notification != 'yes';
    }

    /**
     * Sends OneSignal Push Notifications with User-Tags.
     *
     * This function sends push notifications to specific users based on user-tags. It allows for
     * delaying the push notification if needed.
     *
     * @param array $users An array of User objects to whom the push notifications should be sent.
     * @param int $job_id The ID of the job associated with the push notifications.
     * @param array $data Additional data to be included in the push notifications.
     * @param array $msg_text The message content of the push notifications.
     * @param bool $is_need_delay Indicates whether the push notifications need to be delayed.
     * @return void
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        // Logging push notification sending
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        // Setting OneSignal app ID and authorization key based on environment
        if (env('APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }

        // Constructing push notification fields
        $user_tags = $this->getUserTagsStringFromArray($users);
        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        // Setting custom sounds based on notification type
        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        // Constructing fields array for the push notification
        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        ];

        // Adding 'send_after' field if push notification needs to be delayed
        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        // Sending push notification via OneSignal API
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * Retrieves potential translators for a job.
     *
     * This function fetches potential translators based on the job's requirements such as language,
     * gender, and certification level.
     *
     * @param Job $job The job for which potential translators are being fetched.
     * @return mixed An array of potential translators.
     */
    public function getPotentialTranslators(Job $job)
    {
        // Determining translator type based on job type
        if ($job->job_type == 'paid') {
            $translator_type = 'professional';
        } elseif ($job->job_type == 'rws') {
            $translator_type = 'rwstranslator';
        } elseif ($job->job_type == 'unpaid') {
            $translator_type = 'volunteer';
        }

        // Fetching potential translators based on job requirements
        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];
        // Determining translator levels based on job certification
        // and adding them to the translator level array
        if (!empty($job->certified)) {
            // Adding appropriate certification levels based on job certification
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $translator_level[] = 'Certified with specialisation in law';
            } elseif ($job->certified == 'health' || $job->certified == 'n_health') {
                $translator_level[] = 'Certified with specialisation in health care';
            } elseif ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            } elseif ($job->certified == null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        // Fetching translators blacklisted by the job poster
        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();

        // Fetching potential translators based on the determined parameters
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);
        return $users;
    }

    /**
     * Updates a job with new data.
     *
     * This function updates a job with new data provided and logs the changes made.
     *
     * @param int $id The ID of the job to be updated.
     * @param array $data The new data to update the job with.
     * @param User $cuser The current user performing the update.
     * @return array The result of the update operation.
     */
    public function updateJob($id, $data, $cuser)
    {
        // Fetching the job to be updated
        $job = Job::find($id);

        // Fetching the current translator assigned to the job
        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator)) {
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();
        }

        // Initializing an array to store log data for changes made
        $log_data = [];

        // Flag to indicate if language change occurred during update
        $langChanged = false;

        // Handling translator change during update
        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        // Handling due date change during update
        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        // Handling language change during update
        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        // Handling status change during update
        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        // Updating admin comments
        $job->admin_comments = $data['admin_comments'];

        // Logging the job update operation
        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        // Updating job reference
        $job->reference = $data['reference'];

        // Checking if the job's due date is in the past
        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            // Saving the job and sending notifications for changes if due date is in the future
            $job->save();
            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $old_time);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
        }
    }

    /**
     * Changes the status of a job based on provided data.
     *
     * This function updates the status of a job based on the provided data. It handles various status transitions
     * such as 'timedout', 'completed', 'started', 'pending', 'withdrawafter24', and 'assigned'.
     *
     * @param Job $job The job to update the status for.
     * @param array $data The new data containing the updated status.
     * @param bool $changedTranslator Indicates whether the translator associated with the job has changed.
     * @return array|null An array containing the status change result and log data, or null if no status change occurred.
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;

        // Checking if the status has changed
        if ($old_status != $data['status']) {
            // Handling status change based on the current status
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            // Logging the status change if it occurred
            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }

    /**
     * Changes the status of a job to 'timedout'.
     *
     * This function changes the status of a job to 'timedout' and performs necessary actions
     * such as sending emails and notifications.
     *
     * @param Job $job The job to change the status for.
     * @param array $data The new data containing the updated status.
     * @param bool $changedTranslator Indicates whether the translator associated with the job has changed.
     * @return bool Indicates whether the status change to 'timedout' was successful.
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $job->status = $data['status'];

        // Fetching user data for sending emails
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        // Handling status change to 'pending'
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }
        return false;
    }

    /**
     * Changes the status of a job to 'completed'.
     *
     * This function changes the status of a job to 'completed' and handles any additional actions required.
     *
     * @param Job $job The job to change the status for.
     * @param array $data The new data containing the updated status.
     * @return bool Indicates whether the status change to 'completed' was successful.
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
    }

    /**
     * Changes the status of a job to 'started'.
     *
     * This function updates the status of a job to 'started' and performs additional actions, such as sending
     * notifications and emails when the job status is changed to 'completed'.
     *
     * @param Job $job The job to update the status for.
     * @param array $data The new data containing the updated status and additional information.
     * @return bool Indicates whether the status change to 'started' was successful.
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];

        // Checking if admin comments are provided
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];

        // Handling status change to 'completed'
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();

            // Checking if session time is provided
            if ($data['sesion_time'] == '') return false;
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            // Fetching email information
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            // Sending email notification to customer
            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            // Sending email notification to translator
            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
        }

        $job->save();
        return true;
    }

    /**
     * Changes the status of a job to 'pending'.
     *
     * This function updates the status of a job to 'pending' and performs additional actions, such as sending
     * notifications and emails when the job status is changed to 'assigned' with a changed translator.
     *
     * @param Job $job The job to update the status for.
     * @param array $data The new data containing the updated status and additional information.
     * @param bool $changedTranslator Indicates whether the translator associated with the job has changed.
     * @return bool Indicates whether the status change to 'pending' was successful.
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];

        // Checking if admin comments are provided and the status is not 'timedout'
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];

        // Fetching email information
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        // Handling status change to 'assigned' with a changed translator
        if ($data['status'] == 'assigned' && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);

            // Sending email notification to customer and translator
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            // Handling status change to 'pending' or 'assigned' without a changed translator
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }

        return false;
    }

    /**
     * Sends a session start reminder notification to the user.
     *
     * This method is temporary and should be removed once a service for notification is implemented.
     *
     * @param User $user The user to send the notification to.
     * @param Job $job The job associated with the notification.
     * @param string $language The language of the job.
     * @param string $due The due date and time of the job.
     * @param string $duration The duration of the job.
     * @return void
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        // Logging
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        // Notification data
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);

        // Constructing message text based on physical type
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        // Sending push notification if needed
        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * Changes the status of a job to 'withdrawafter24'.
     *
     * This function updates the status of a job to 'withdrawafter24' if the provided status is 'timedout'.
     *
     * @param Job $job The job to update the status for.
     * @param array $data The new data containing the updated status and additional information.
     * @return bool Indicates whether the status change to 'withdrawafter24' was successful.
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * Changes the status of a job to 'assigned'.
     *
     * This function updates the status of a job to 'assigned' if the provided status is 'withdrawbefore24',
     * 'withdrawafter24', or 'timedout'. It also handles additional actions, such as sending notifications.
     *
     * @param Job $job The job to update the status for.
     * @param array $data The new data containing the updated status and additional information.
     * @return bool Indicates whether the status change to 'assigned' was successful.
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                // Fetching email information
                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                // Sending email notification to customer
                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                // Sending email notification to translator
                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }
    /**
     * Changes the translator for a job.
     *
     * This method checks if the translator needs to be changed based on the provided data.
     * If a change is necessary, it updates the translator and logs the change.
     *
     * @param Translator|null $current_translator The current translator associated with the job.
     * @param array $data The new data containing the updated translator information.
     * @param Job $job The job for which the translator is being changed.
     * @return array An array indicating whether the translator was changed and the new translator information.
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * Changes the due date of a job.
     *
     * This method compares the old and new due dates and logs the change if they differ.
     *
     * @param string $old_due The old due date of the job.
     * @param string $new_due The new due date of the job.
     * @return array An array indicating whether the due date was changed and the old and new due dates.
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    /**
     * Sends notifications about the changed translator for a job.
     *
     * This method sends notifications to the customer, old translator (if any), and new translator
     * about the changed translator for the job.
     *
     * @param Job $job The job for which the translator was changed.
     * @param Translator|null $current_translator The current translator associated with the job.
     * @param Translator $new_translator The new translator associated with the job.
     * @return void
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * Sends notifications about the changed date of a job.
     *
     * This method sends notifications to the customer and assigned translator about the changed date of a job.
     *
     * @param Job $job The job for which the date was changed.
     * @param string $old_time The old due date and time of the job.
     * @return void
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        // Send notification to the customer
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        // Send notification to the assigned translator
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data['user'] = $translator;
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Sends notifications about the changed language of a job.
     *
     * This method sends notifications to the customer and assigned translator about the changed language of a job.
     *
     * @param Job $job The job for which the language was changed.
     * @param string $old_lang The old language of the job.
     * @return void
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        // Send notification to the customer
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        // Send notification to the assigned translator
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data['user'] = $translator;
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Sends a job expired notification to the user.
     *
     * This method sends a notification to the user when their job expires.
     *
     * @param Job $job The expired job.
     * @param User $user The user associated with the job.
     * @return void
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = [];
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Sends notifications for admin-cancelled jobs.
     *
     * This method sends notifications to the translators when a job is cancelled by the admin.
     *
     * @param int $job_id The ID of the cancelled job.
     * @return void
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = [
            'job_id'             => $job->id,
            'from_language_id'   => $job->from_language_id,
            'immediate'          => $job->immediate,
            'duration'           => $job->duration,
            'status'             => $job->status,
            'gender'             => $job->gender,
            'certified'          => $job->certified,
            'due'                => $job->due,
            'job_type'           => $job->job_type,
            'customer_phone_type'=> $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town'     => $user_meta->city,
            'customer_type'     => $user_meta->customer_type
        ];

        $due_Date = explode(" ", $job->due);
        $data['due_date'] = $due_Date[0];
        $data['due_time'] = $due_Date[1];
        $data['job_for'] = [];

        if ($job->gender !== null) {
            $data['job_for'][] = $job->gender === 'male' ? 'Man' : 'Kvinna';
        }
        if ($job->certified !== null) {
            $data['job_for'][] = $job->certified === 'both' ? ['normal', 'certified'] : $job->certified;
        }
        
        // Send notification to suitable translators
        $this->sendNotificationTranslator($job, $data, '*');
    }

    /**
     * Sends session start reminder notifications.
     *
     * This method sends reminder notifications to users before the start of a session.
     *
     * @param User $user The user to whom the reminder is sent.
     * @param Job $job The job for which the reminder is sent.
     * @param string $language The language of the job.
     * @param string $due The due date of the job.
     * @param int $duration The duration of the job.
     * @return void
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = [
            'notification_type' => 'session_start_remind'
        ];

        $msg_text = ($job->customer_physical_type == 'yes') ?
            ["en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'] :
            ["en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Generates a user tags string from an array of users for creating OneSignal notifications.
     *
     * @param array $users An array of users.
     * @return string The user tags string.
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;

        foreach ($users as $oneUser) {
            if (!$first) {
                $user_tags .= ',{"operator": "OR"},';
            } else {
                $first = false;
            }

            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }

        $user_tags .= ']';
        return $user_tags;
    }

    /**
     * Accepts a job and sends notifications accordingly.
     *
     * This method accepts a job for a user and sends notifications about the acceptance.
     *
     * @param array $data The job acceptance data.
     * @param User $user The user accepting the job.
     * @return array The response array indicating the status of the acceptance.
     */
    public function acceptJob($data, $user)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();
                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = ['user' => $user, 'job' => $job];
                // Send notification to the customer
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
                // Send push notification to the customer
                $this->sendJobAcceptedNotification($job, $user);
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Denna tolkning har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    /**
     * Accepts a job with the given job ID and sends notifications accordingly.
     *
     * This method accepts a job with the given job ID for a user and sends notifications about the acceptance.
     *
     * @param int $job_id The ID of the job to be accepted.
     * @param User $cuser The user accepting the job.
     * @return array The response array indicating the status of the acceptance.
     */
    public function acceptJobWithId($job_id, $cuser)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();
                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = ['user' => $user, 'job' => $job];
                // Send notification to the customer
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
                // Send push notification to the customer
                $this->sendJobAcceptedNotification($job, $user);
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Denna tolkning har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = array();
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                // Increment customer's number of bookings if cancelled within 24 hours
                // Charge customer for the cancellation if within 24 hours
                $response['jobstatus'] = 'success';
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                // Notify translator about the cancellation
                $this->notifyTranslatorAboutCancellation($translator, $job);
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->first();
                if ($customer) {
                    // Notify customer about the cancellation
                    $this->notifyCustomerAboutCancellation($customer, $job);
                }
                $job->status = 'pending';
                $job->created_at = now()->toDateTimeString();
                $job->will_expire_at = TeHelper::willExpireAt($job->due, now()->toDateTimeString());
                $job->save();

                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all suitable translators
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    /*Function to get the potential jobs for paid, rws, unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';
        $translator_type = $cuser_meta->translator_type;
        if ($translator_type == 'professional') {
            $job_type = 'paid';   // show all jobs for professionals.
        } elseif ($translator_type == 'rwstranslator') {
            $job_type = 'rws';  // for rwstranslator only show rws jobs.
        } elseif ($translator_type == 'volunteer') {
            $job_type = 'unpaid';  // for volunteers only show unpaid jobs.
        }

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        // Call the town function for checking if the job is physical, then translators in one town can get job
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if ($job->specific_job == 'SpecificJob') {
                if ($job->check_particular_job == 'userCanNotAcceptJob') {
                    unset($job_ids[$k]);
                }
            }

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }

        return $job_ids;
    }

    public function endJob($postData)
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
    
        if ($jobDetail->status != 'started') {
            return ['status' => 'success'];
        }
    
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;
    
        $user = $job->user()->get()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $sessionExplode = explode(':', $job->session_time);
        $sessionTime = $sessionExplode[0] . ' tim ' . $sessionExplode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    
        $job->save();
    
        $translatorRel = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
    
        Event::fire(new SessionEnded($job, ($postData['user_id'] == $job->user_id) ? $translatorRel->user_id : $job->user_id));
    
        $translator = $translatorRel->user()->first();
        $email = $translator->email;
        $name = $translator->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $translator,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'lön'
        ];
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    
        $translatorRel->completed_at = $completedDate;
        $translatorRel->completed_by = $postData['user_id'];
        $translatorRel->save();
    
        $response['status'] = 'success';
        return $response;
    }
    
    public function customerNotCall($postData)
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';
    
        $translatorRel = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $translatorRel->completed_at = $completedDate;
        $translatorRel->completed_by = $translatorRel->user_id;
        $job->save();
        $translatorRel->save();
    
        $response['status'] = 'success';
        return $response;
    }
    
    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $currentUser = $request->__authenticatedUser;
        $consumerType = $currentUser->consumer_type;
    
        if ($currentUser && $currentUser->user_type == env('SUPERADMIN_ROLE_ID')) {
            // Admin logic
        } else {
            // Non-admin logic
        }
    
        return $allJobs;
    }
    public function alerts()
    {
        // Retrieve all jobs
        $jobs = Job::all();
        
        // Initialize arrays for session jobs and job IDs
        $sesJobs = [];
        $jobId = [];
        
        // Array to store session time differences
        $diff = [];
        
        // Counter for indexing
        $i = 0;
    
        // Loop through each job
        foreach ($jobs as $job) {
            // Split session time into hours, minutes, and seconds
            $sessionTime = explode(':', $job->session_time);
            
            // Ensure session time has at least hours, minutes, and seconds
            if (count($sessionTime) >= 3) {
                // Calculate session time in minutes
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
    
                // Check if session time is twice the duration
                if ($diff[$i] >= $job->duration * 2) {
                    $sesJobs[$i] = $job;
                }
                $i++;
            }
        }
    
        // Extract job IDs from session jobs
        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }
    
        // Retrieve active languages
        $languages = Language::where('active', '1')->orderBy('language')->get();
        
        // Get all customer and translator emails
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');
    
        // Get authenticated user and consumer type
        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');
    
        // Initialize query builder for jobs
        $allJobs = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->whereIn('jobs.id', $jobId);
    
        // Apply filters based on user role
        if ($cuser && $cuser->is('superadmin')) {
            // Admin logic
            
        } elseif ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            // Admin or superadmin logic
    
        }
        
        // Return all jobs with languages, customers, translators, and request data
        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];
    }
    
    public function userLoginFailed()
    {
        // Retrieve throttles with associated users
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);
    
        // Return throttles data
        return ['throttles' => $throttles];
    }
    
    public function bookingExpireNoAccepted()
    {
        // Retrieve active languages
        $languages = Language::where('active', '1')->orderBy('language')->get();
        
        // Get all customer and translator emails
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');
    
        // Get authenticated user and consumer type
        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');
    
        // Initialize query builder for jobs
        $allJobs = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->where('jobs.ignore_expired', 0);
    
        // Apply filters based on user role
        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            // Admin or superadmin logic
    
        }
        
        // Return all jobs with languages, customers, translators, and request data
        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];
    }
    /**
     * Ignore the expiring status of a job.
     *
     * @param int $id The ID of the job to ignore.
     * @return array An array indicating success and a message.
     */
    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    /**
     * Ignore the expired status of a job.
     *
     * @param int $id The ID of the job to ignore.
     * @return array An array indicating success and a message.
     */
    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    /**
     * Ignore the throttling status of a job.
     *
     * @param int $id The ID of the throttle to ignore.
     * @return array An array indicating success and a message.
     */
    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    /**
     * Reopen a job.
     *
     * @param array $request The request containing job and user IDs.
     * @return array An array indicating success or failure.
     */
    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);
        $job = $job->toArray();

        $data = [];
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userid;
        $data['job_id'] = $jobid;
        $data['cancel_at'] = Carbon::now();

        $datareopen = [];
        $datareopen['status'] = 'pending';
        $datareopen['created_at'] = Carbon::now();
        $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        $Translator = Translator::create($data);
        
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }
        
    /**
     * Convert number of minutes to hour and minute format.
     *
     * @param int $time The number of minutes.
     * @param string $format The format string for the output.
     * @return string The formatted time string.
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } elseif ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }

}
