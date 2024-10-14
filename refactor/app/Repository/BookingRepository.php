<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
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
use Symfony\Component\HttpFoundation\Response;

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
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    // Main methods used in Booking Controller

    /**
     * Returns User's Jobs
     *
     * @param int $user_id
     * @return array
     */
    public function getUsersJobs(int $user_id): array
    {
        $causer = User::find($user_id);
        $emergencyJobs = [];
        $normalJobs = [];
        $usertype = '';

        if ($causer) {
            if ($causer->is('customer')) {
                $jobs = $causer->jobs()
                    ->with([
                        'user.userMeta',
                        'user.average',
                        'translatorJobRel.user.average',
                        'language',
                        'feedback'
                    ])
                    ->whereIn('status', ['pending', 'assigned', 'started'])
                    ->orderBy('due', 'asc')
                    ->get();
                $usertype = 'customer';
            } elseif ($causer->is('translator')) {
                $jobs = $this->model->getTranslatorJobs($causer->id, 'new')
                    ->pluck('jobs')
                    ->all();
                $usertype = 'translator';
            } else {
                $jobs = collect();
            }
            foreach ($jobs as $jobItem) {
                if ($jobItem->immediate == 'yes') {
                    $emergencyJobs[] = $jobItem;
                } else {
                    $normalJobs[] = $jobItem;
                }
            }

            $normalJobs = collect($normalJobs)
                ->each(function ($jobItem) use ($user_id) {
                    $jobItem['usercheck'] = $this->model->checkParticularJob($user_id, $jobItem);
                })
                ->sortBy('due')
                ->all();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $causer,
            'usertype' => $usertype
        ];
    }

    /**
     * Get All Jobs
     *
     * @param Request $request
     * @param $limit
     * @return array
     */
    /**
     * Get All Jobs
     *
     * @param Request $request
     * @param $limit
     * @return array
     */
    public function getAll(Request $request, $limit = null): array
    {
        $requestData = $request->all();
        $causer = $request->__authenticatedUser;
        $consumerType = $causer->consumer_type;

        $allJobs = $this->model->query();

        // Apply common filters
        $this->applyCommonFilters($allJobs, $requestData);

        // Apply time-based filters
        $this->applyTimeFilters($allJobs, $requestData);

        // Feedback filter logic
        if ($this->applyFeedbackFilter($allJobs, $requestData)) {
            return ['count' => $allJobs->count()];
        }

        // SuperAdmin-specific filters
        if ($causer && $causer->user_type == env('SUPERADMIN_ROLE_ID')) {
            $this->applySuperAdminFilters($allJobs, $requestData);
        } else {
            // Non-admin users filter
            $this->applyConsumerFilters($allJobs, $consumerType);
        }

        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        // Paginate or return all jobs
        return ($limit === 'all') ? $allJobs->get() : $allJobs->paginate(15);
    }


    /**
     * @param $user
     * @param $data
     * @return array
     * @throws ValidationException
     */
    public function store($user, $data): array
    {
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;
        $response = [];

        if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
            throw ValidationException::withMessages(['user' => "Translator cannot create booking"]);
        } else {
            $causer = $user;
            $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
            $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

            if ($data['immediate'] == 'yes') {
                $due_carbon = Carbon::now()->addMinute($immediatetime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';
            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);

                if ($due_carbon->isPast()) {
                    throw ValidationException::withMessages([
                        'due' => "Can't create booking in the past"
                    ]);
                }
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $response['type'] = 'regular';
            }

            $this->setGenderAndCertification($data); // Custom method to handle gender and certification

            if ($consumer_type == 'rwsconsumer') {
                $data['job_type'] = 'rws';
            } elseif ($consumer_type == 'ngo') {
                $data['job_type'] = 'unpaid';
            } elseif ($consumer_type == 'paid') {
                $data['job_type'] = 'paid';
            }

            $data['b_created_at'] = now();
            if (isset($data['due'])) {
                $data['will_expire_at'] = TeHelper::willExpireAt($data['due'], $data['b_created_at']);
            }
            $data['by_admin'] = $data['by_admin'] ?? 'no';

            $job = $causer->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
            $response['job_for'] = $this->getJobForBasedOnCertification($job);
            $response['customer_town'] = $causer->userMeta->city;
            $response['customer_type'] = $causer->userMeta->customer_type;
        }

        return $response;
    }

    /**
     * Update Job
     *
     * @param $id
     * @param $data
     * @param $cuser
     * @return bool
     */
    public function updateJob($id, $data, $cuser): bool
    {
        $job = $this->model->findOrFail($id);
        $current_translator = $job->translatorJobRel->where('cancel_at', null)->first() ??
            $job->translatorJobRel->where('completed_at', '!=', null)->first();

        $log_data = [];

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($old_lang),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ') has updated booking #' . $id, $log_data);

        $job->save();

        if ($job->due > Carbon::now()) {
            if ($changeDue['dateChanged'])
                $this->sendChangedDateNotification($job, $old_time);

            if ($changeTranslator['translatorChanged'])
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);

            if (isset($langChanged) && $langChanged)
                $this->sendChangedLangNotification($job, $old_lang);
        }

        return true;
    }

    /**
     * Get History of Jobs
     *
     * @param $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request): array
    {
        $page = $request->get('page', 1);
        $causer = User::findOrFail($user_id);
        $response = [
            'jobs' => [],
            'cuser' => $causer,
            'usertype' => '',
            'numpages' => 0,
            'pagenum' => $page,
        ];

        if ($causer->is('customer')) {
            $jobs = $causer->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

            $response['jobs'] = $jobs;
            $response['usertype'] = 'customer';
            $response['numpages'] = $jobs->lastPage();

        } elseif ($causer->is('translator')) {
            $jobs = $this->model->getTranslatorJobsHistoric($causer->id, 'historic', $page);

            $response['jobs'] = $jobs;
            $response['usertype'] = 'translator';
            $response['numpages'] = $jobs->lastPage();
        }

        return $response;
    }

    /**
     * Function to get the potential jobs for paid,rws,unpaid translators
     *
     * @param $causer
     * @return mixed
     */
    public function getPotentialJobs($causer): mixed
    {
        $causerMeta = $causer->userMeta;
        $job_type = match ($causerMeta->translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid',
        };

        $userLanguages = UserLanguages::where('user_id', $causer->id)->pluck('lang_id')->all();

        $job_ids = $this->model->getJobs($causer->id, $job_type, 'pending', $userLanguages, $causerMeta->gender, $causerMeta->translator_level);

        foreach ($job_ids as $k => $job) {
            $job->specific_job = $this->model->assignedToPaticularTranslator($causer->id, $job->id);
            $job->check_particular_job = $this->model->checkParticularJob($causer->id, $job);

            if ($job->specific_job === 'SpecificJob' && $job->check_particular_job === 'userCanNotAcceptJob') {
                unset($job_ids[$k]);
                continue;
            }

            $isSameTown = $this->model->checkTowns($job->user_id, $causer->id);

            if (($job->customer_phone_type === 'no' || $job->customer_phone_type === '')
                && $job->customer_physical_type === 'yes'
                && !$isSameTown) {
                unset($job_ids[$k]);
            }
        }

        return $job_ids;
    }


    // Main methods used in Notification Controller

    /**
     * Generate Data of Job for Notifications
     *
     * @param $job
     * @return array
     */
    public function jobToData($job): array
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
            'job_for' => [],
        ];

        $dueComponents = explode(" ", $job->due);
        $data['due_date'] = $dueComponents[0] ?? '';
        $data['due_time'] = $dueComponents[1] ?? '';

        if ($job->gender) {
            $data['job_for'][] = ($job->gender === 'male') ? 'Man' : 'Kvinna';
        }

        if ($job->certified) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law':
                case 'n_law':
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
                    break;
            }
        }

        return $data;
    }

    /**
     * Send Notification to Translators
     *
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id): void
    {
        $users = User::where('user_type', '2')->where('status', '1')->where('id', '!=', $exclude_user_id)->get();
        $translator_array = [];
        $delpay_translator_array = [];

        foreach ($users as $oneUser) {
            if (!$this->isNeedToSendPush($oneUser->id)) continue;

            $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
            if ($data['immediate'] === 'yes' && $not_get_emergency === 'yes') continue;

            $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id);

            foreach ($jobs as $oneJob) {
                if ($job->id !== $oneJob->id) continue;

                $userId = $oneUser->id;
                $job_for_translator = $this->model->assignedToPaticularTranslator($userId, $oneJob->id);
                if ($job_for_translator !== 'SpecificJob') continue;

                $job_checker = $this->model->checkParticularJob($userId, $oneJob);
                if ($job_checker === 'userCanNotAcceptJob') continue;

                // Determine if push notification needs to be delayed
                if ($this->isNeedToDelayPush($oneUser->id)) {
                    $delpay_translator_array[] = $oneUser;
                } else {
                    $translator_array[] = $oneUser;
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = ($data['immediate'] === 'no')
            ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due']
            : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        $msg_text = ['en' => $msg_contents];

        $this->logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);

        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false); // immediate
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // delayed
    }

    /**
     * Function to get all Potential jobs of user with his ID
     *
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id): array
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;

        $job_type = match ($translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid',
        };

        $user_languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;

        $job_ids = $this->model->getJobs($user_id, $job_type, 'pending', $user_languages, $gender, $translator_level);

        foreach ($job_ids as $k => $job) {
            $job = $this->model->find($job->id);
            $checktown = $this->model->checkTowns($job->user_id, $user_id);

            if (($job->customer_phone_type === 'no' || $job->customer_phone_type === '')
                && $job->customer_physical_type === 'yes'
                && !$checktown) {
                unset($job_ids[$k]);
            }
        }

        return TeHelper::convertJobIdsInObjs($job_ids);
    }


    /**
     * Function to send OneSignal Push Notifications with User-Tags
     *
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param bool $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay): void
    {
        $this->logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = env('APP_ENV') === 'prod' ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", env('APP_ENV') === 'prod' ? config('app.prodOnesignalApiKey') : config('app.devOnesignalApiKey'));

        $user_tags = $this->getUserTagsStringFromArray($users);
        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] === 'suitable_job') {
            $ios_sound = $data['immediate'] === 'no' ? 'normal_booking.mp3' : 'emergency_booking.mp3';
            $android_sound = $data['immediate'] === 'no' ? 'normal_booking' : 'emergency_booking';
        }

        $fields = json_encode([
            'app_id' => $onesignalAppID,
            'tags' => json_decode($user_tags),
            'data' => $data,
            'title' => ['en' => 'DigitalTolk'],
            'contents' => $msg_text,
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound' => $android_sound,
            'ios_sound' => $ios_sound,
            'send_after' => $is_need_delay ? DateTimeHelper::getNextBusinessTimeString() : null,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);

        $this->logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * Sends SMS to translators and returns the count of translators notified.
     *
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job): int
    {
        // Get potential translators for the job
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // Prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ?: $jobPosterMeta->city; // Fallback to job poster's city if not set

        if ($job->customer_physical_type === 'yes' && $job->customer_phone_type === 'no') {
            $message = trans('sms.physical_job', [
                'date' => $date,
                'time' => $time,
                'town' => $city,
                'duration' => $duration,
                'jobId' => $jobId,
            ]);
        } else {
            $message = trans('sms.phone_job', [
                'date' => $date,
                'time' => $time,
                'duration' => $duration,
                'jobId' => $jobId,
            ]);
        }

        $this->logger->addInfo($message);

        // Send messages to each translator and log the status
        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            $this->logger->addInfo(("Send SMS to {$translator->email} ({$translator->mobile}), status: " . print_r($status, true)));
        }

        return count($translators);
    }

    /**
     * Get potential translators for a given job.
     *
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job): mixed
    {
        $translator_type = match ($job->job_type) {
            'paid' => 'professional',
            'rws' => 'rwstranslator',
            'unpaid' => 'volunteer',
            default => null,
        };

        $jobLanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];
        if (is_null($job->certified)) {
            $translator_level = [
                'Certified',
                'Certified with specialisation in law',
                'Certified with specialisation in health care',
                'Layman',
                'Read Translation courses',
            ];
        } else {
            if (in_array($job->certified, ['yes', 'both'])) {
                $translator_level = [
                    'Certified',
                    'Certified with specialisation in law',
                    'Certified with specialisation in health care',
                ];
            } elseif (in_array($job->certified, ['law', 'n_law'])) {
                $translator_level[] = 'Certified with specialisation in law';
            } elseif (in_array($job->certified, ['health', 'n_health'])) {
                $translator_level[] = 'Certified with specialisation in health care';
            } else {
                $translator_level = [
                    'Layman',
                    'Read Translation courses',
                ];
            }
        }

        $translatorsId = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();

        return User::getPotentialUsers($translator_type, $jobLanguage, $gender, $translator_level, $translatorsId);
    }

    /**
     * Store job email and send notification.
     *
     * @param array $data
     * @return array
     */
    public function storeJobEmail($data)
    {
        $job = $this->model->findOrFail($data['user_email_job_id'] ?? null);
        $user = $job->user()->first();

        // Update job details
        $job->user_email = $data['user_email'] ?? '';
        $job->reference = $data['reference'] ?? '';

        if (isset($data['address'])) {
            $job->address = $data['address'] ?: $user->userMeta->address;
            $job->instructions = $data['instructions'] ?: $user->userMeta->instructions;
            $job->town = $data['town'] ?: $user->userMeta->city;
        }

        $job->save();

        // Prepare email details
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job' => $job,
        ];

        // Send email
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        // Prepare and trigger response
        $response = [
            'type' => $data['user_type'],
            'job' => $job,
            'status' => 'success',
        ];
        $eventData = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $eventData, '*'));

        return $response;
    }

    // Main Methods for Status Controller

    /**
     * Accept a job for the translator.
     *
     * @param $data
     * @param $user
     * @return array
     * @throws \Exception
     */
    public function acceptJob($data, $user): array
    {
        $jobId = $data['job_id'];
        $job = $this->model->findOrFail($jobId);
        $currentUserId = $user->id;

        if ($this->model->isTranslatorAlreadyBooked($jobId, $currentUserId, $job->due)) {
            throw new \Exception('Du har redan en bokning den tiden! Bokningen är inte accepterad.', Response::HTTP_BAD_REQUEST);
        }

        if ($job->status === 'pending' && $this->model->insertTranslatorJobRel($currentUserId, $jobId)) {
            $this->processJobAcceptance($job);

            $jobs = $this->getPotentialJobs($user);
            return [
                'list' => json_encode(['jobs' => $jobs, 'job' => $job]),
            ];
        }

        throw new \Exception('Job status is not pending or the job could not be accepted.', Response::HTTP_BAD_REQUEST);
    }

    /**
     * Accept the job with the given job ID.
     *
     * @param int $job_id
     * @param User $causer
     * @return array
     * @throws \Exception
     */
    public function acceptJobWithId($job_id, $causer): array
    {
        $job = $this->model->findOrFail($job_id);
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

        if ($this->model->isTranslatorAlreadyBooked($job_id, $causer->id, $job->due)) {
            throw new \Exception('Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning', Response::HTTP_BAD_REQUEST);
        }

        if ($job->status === 'pending' && $this->model->insertTranslatorJobRel($causer->id, $job_id)) {
            $this->processJobAcceptance($job);
            $user = $job->user()->first();

            $data = ['notification_type' => 'job_accepted'];
            $msg_text = [
                "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
            ];

            if ($this->isNeedToSendPush($user->id)) {
                $users_array = [$user];
                $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
            }

            return [
                'list' => ['job' => $job],
                'message' => 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due
            ];
        } else {
            throw new \Exception('Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning', Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Process the acceptance of a job.
     *
     * @param Job $job
     * @return void
     */
    private function processJobAcceptance($job): void
    {
        $job->status = 'assigned';
        $job->save();

        $userDetails = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $userDetails->email;
        $name = $userDetails->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

        $dataToSend = [
            'user' => $userDetails,
            'job' => $job
        ];

        // Send confirmation email
        (new AppMailer())->send($email, $name, $subject, 'emails.job-accepted', $dataToSend);
    }

    /**
     * Cancel Job
     *
     * @param $data
     * @param $user
     * @return bool
     * @throws \Exception
     */
    public function cancelJobAjax($data, $user): bool
    {
        $jobId = $data['job_id'];
        $job = $this->model->findOrFail($jobId);
        $translator = $this->model->getJobsAssignedTranslatorDetail($job);
        $currentTime = Carbon::now();
        $withdrawalTimeDiff = $currentTime->diffInHours($job->due);
        $job->withdraw_at = $currentTime;

        if ($user->is('customer')) {
            // Set job status based on withdrawal time
            $job->status = $withdrawalTimeDiff >= 24 ? 'withdrawbefore24' : 'withdrawafter24';
            $job->save();
            Event::fire(new JobWasCanceled($job));

            // Prepare response
            $response = [
                'jobstatus' => 'success',
                'status' => 'success',
            ];

            // Notify translator if assigned
            if ($translator) {
                $data = ['notification_type' => 'job_cancelled'];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msgText = [
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ];

                if ($this->isNeedToSendPush($translator->id)) {
                    $this->sendPushNotificationToSpecificUsers([$translator], $jobId, $data, $msgText, $this->isNeedToDelayPush($translator->id));
                }
            }
        } else {
            // If the user is a translator
            if ($withdrawalTimeDiff > 24) {
                $customer = $job->user()->first();
                if ($customer) {
                    $data = ['notification_type' => 'job_cancelled'];
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msgText = [
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    ];

                    if ($this->isNeedToSendPush($customer->id)) {
                        $this->sendPushNotificationToSpecificUsers([$customer], $jobId, $data, $msgText, $this->isNeedToDelayPush($customer->id));
                    }
                }

                // Update job status and related data
                $job->status = 'pending';
                $job->created_at = now();
                $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
                $job->save();
                $this->model->deleteTranslatorJobRel($translator->id, $jobId);

                $data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $data, $translator->id); // Notify suitable translators
            } else {
                throw new \Exception('Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!', Response::HTTP_BAD_REQUEST);
            }
        }

        return true;
    }

    /**
     * End Job
     *
     * @param $data
     * @return bool
     */
    public function endJob($data): bool
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $data['job_id'];

        $jobDetail = $this->model->with('translatorJobRel')->findOrFail($jobId);

        if ($jobDetail->status != 'started') {
            return true;
        }

        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;

        $jobDetail->end_at = $completedDate;
        $jobDetail->status = 'completed';
        $jobDetail->session_time = $interval;
        $jobDetail->save();

        $user = $jobDetail->user()->first();
        $email = $jobDetail->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;
        $sessionExplode = explode(':', $jobDetail->session_time);
        $sessionTime = $sessionExplode[0] . ' tim ' . $sessionExplode[1] . ' min';

        $data = [
            'user' => $user,
            'job' => $jobDetail,
            'session_time' => $sessionTime,
            'for_text' => 'faktura'
        ];

        // Send email notification to the customer
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        // Get the translator-job relation and fire session ended event
        $translatorJobRel = $jobDetail->translatorJobRel()
            ->whereNull('completed_at')
            ->whereNull('cancel_at')
            ->first();

        if ($translatorJobRel) {
            Event::fire(new SessionEnded(
                $jobDetail,
                ($data['user_id'] == $jobDetail->user_id) ? $translatorJobRel->user_id : $jobDetail->user_id
            ));

            // Send email notification to the translator
            $translator = $translatorJobRel->user()->first();
            $translatorEmail = $translator->email;
            $translatorName = $translator->name;
            $translatorSubject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;

            $translatorData = [
                'user' => $translator,
                'job' => $jobDetail,
                'session_time' => $sessionTime,
                'for_text' => 'lön'
            ];

            $mailer->send($translatorEmail, $translatorName, $translatorSubject, 'emails.session-ended', $translatorData);

            // Mark translator job relation as completed
            $translatorJobRel->completed_at = $completedDate;
            $translatorJobRel->completed_by = $data['user_id'];
            $translatorJobRel->save();
        }

        return true;
    }

    /**
     * Handle job where customer did not call
     *
     * @param $data
     * @return bool
     */
    public function customerNotCall($data): bool
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $data["job_id"];

        $jobDetail = $this->model->with('translatorJobRel')->findOrFail($jobId);

        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;

        $jobDetail->end_at = $completedDate;
        $jobDetail->status = 'not_carried_out_customer';
        $jobDetail->session_time = $interval; // Optionally store session time if needed

        $translatorJobRel = $jobDetail->translatorJobRel()
            ->whereNull('completed_at')
            ->whereNull('cancel_at')
            ->first();

        if ($translatorJobRel) {
            $translatorJobRel->completed_at = $completedDate;
            $translatorJobRel->completed_by = $translatorJobRel->user_id;
            $translatorJobRel->save();
        }

        $jobDetail->save();

        return true;
    }


    /**
     * Reopen a Job
     *
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function reopen(array $data): array
    {
        $jobId = $data['jobid'];
        $userId = $data['userid'];

        // Find the job by id
        $job = $this->model->findOrFail($jobId)->toArray();

        // Data for creating translator relation
        $translatorData = [
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
            'updated_at' => now(),
            'user_id' => $userId,
            'job_id' => $jobId,
            'cancel_at' => Carbon::now()
        ];

        // Data for reopening the job
        $dataReopen = [
            'status' => 'pending',
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], now())
        ];

        // If job is not timed out, update the job status and set it to pending
        if ($job['status'] != 'timedout') {
            $affectedRows = $this->model->where('id', $jobId)->update($dataReopen);
            $newJobId = $jobId;
        } else {
            // If job is timed out, create a new job entry with updated information
            $job['status'] = 'pending';
            $job['created_at'] = now();
            $job['updated_at'] = now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], now());
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobId;

            $affectedRows = $this->model->create($job);
            $newJobId = $affectedRows['id'];
        }

        // Update the translator job relationship and mark it as canceled
        Translator::where('job_id', $jobId)->whereNull('cancel_at')->update(['cancel_at' => $translatorData['cancel_at']]);

        // Create a new translator job relation
        Translator::create($translatorData);

        // Send notifications and return success or failure based on operation outcome
        if ($affectedRows) {
            $this->sendNotificationByAdminCancelJob($newJobId);
            return [
                "message" => "Tolk cancelled!",
                "status" => "success"
            ];
        }

        throw new \Exception('Please Try Again', Response::HTTP_BAD_REQUEST);
    }

    // Private function used in this repository

    /**
     * Function to check if push notifications need to be delayed based on user preferences and time of day.
     *
     * @param int $userId
     * @return bool
     */
    public function isNeedToDelayPush(int $userId): bool
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }

        $notGetNighttime = TeHelper::getUsermeta($userId, 'not_get_nighttime');

        return $notGetNighttime === 'yes';
    }

    /**
     * Function to check if need to send the push
     *
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id): bool
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');

        return $not_get_notification !== 'yes';
    }


    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator): array
    {
        $old_status = $job->status;
        $response = [];
        if ($old_status != $data['status']) {
            $statusChanged = match ($job->status) {
                'timedout' => $this->changeTimedoutStatus($job, $data, $changedTranslator),
                'completed' => $this->changeCompletedStatus($job, $data),
                'started' => $this->changeStartedStatus($job, $data),
                'pending' => $this->changePendingStatus($job, $data, $changedTranslator),
                'withdrawafter24' => $this->changeWithdrawafter24Status($job, $data),
                'assigned' => $this->changeAssignedStatus($job, $data),
                default => false,
            };

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $response = ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
        return $response;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator): bool
    {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];
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
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data): bool
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
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data): bool
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['sesion_time'] == '') return false;
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $dataEmail = [
                'user' => $user,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user' => $user,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        }
        $job->save();

        return true;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator): bool
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {

            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = $this->model->getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }
    }

    /*
     * TODO remove method and add service for notification
     *
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration): void
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data): bool
    {
        if ($data['status'] == 'timedout') {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();

            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data): bool
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job' => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job' => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();

            return true;
        }

        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job): array
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && (isset($data['translator']) && $data['translator'] != 0)) {
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
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due): array
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
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator): void
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
            'job' => $job
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
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time): void
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user' => $user,
            'job' => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = $this->model->getJobsAssignedTranslatorDetail($job);
        $data = [
            'user' => $translator,
            'job' => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang): void
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user' => $user,
            'job' => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = $this->model->getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     *
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user): void
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     *
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id): void
    {
        $job = $this->model->findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = $this->getJobForBasedOnCertification($job);
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration): void
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        else
            $msg_text = array(
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users): string
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }


    public function alerts(): array
    {
        $jobs = $this->model->all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function ignoreExpiring($id)
    {
        $job = $this->model->find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = $this->model->find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }


    /**
     * Convert number of minutes to hour and minute variant
     * @param int $time
     * @param string $format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }

    /**
     * Compute Get Job For via Certification
     *
     * @param $job
     * @return array
     */
    private function getJobForBasedOnCertification($job): array
    {
        $jobFor = [];

        if ($job->certified !== null) {
            switch ($job->certified) {
                case 'both':
                    $jobFor[] = 'normal';
                    $jobFor[] = 'certified';
                    break;
                case 'yes':
                    $jobFor[] = 'certified';
                    break;
                default:
                    $jobFor[] = $job->certified;
                    break;
            }
        }

        return $jobFor;
    }

    /**
     * Set Gender & Certification
     *
     * @param $data
     * @return void
     */
    protected function setGenderAndCertification(&$data): void
    {
        if (in_array('male', $data['job_for'])) {
            $data['gender'] = 'male';
        } elseif (in_array('female', $data['job_for'])) {
            $data['gender'] = 'female';
        }

        if (in_array('normal', $data['job_for'])) {
            $data['certified'] = 'normal';
        } elseif (in_array('certified', $data['job_for'])) {
            $data['certified'] = 'yes';
        } elseif (in_array('certified_in_law', $data['job_for'])) {
            $data['certified'] = 'law';
        } elseif (in_array('certified_in_health', $data['job_for'])) {
            $data['certified'] = 'health';
        }

        if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
            $data['certified'] = 'both';
        }
    }

    /**
     * Apply Common Filter
     *
     * @param $query
     * @param array $requestData
     * @return void
     */
    private function applyCommonFilters($query, array $requestData): void
    {
        if (!empty($requestData['id'])) {
            $query->whereIn('id', (array)$requestData['id']);
        }

        if (!empty($requestData['lang'])) {
            $query->whereIn('from_language_id', $requestData['lang']);
        }

        if (!empty($requestData['status'])) {
            $query->whereIn('status', $requestData['status']);
        }

        if (!empty($requestData['job_type'])) {
            $query->whereIn('job_type', $requestData['job_type']);
        }

        if (!empty($requestData['customer_email'])) {
            $this->filterByEmails($query, $requestData['customer_email'], 'user_id');
        }

        if (!empty($requestData['translator_email'])) {
            $this->filterByTranslatorEmails($query, $requestData['translator_email']);
        }
    }

    /**
     * Apply time Filters
     *
     * @param $query
     * @param array $requestData
     * @return void
     */
    private function applyTimeFilters($query, array $requestData): void
    {
        if (isset($requestData['filter_timetype']) && in_array($requestData['filter_timetype'], ['created', 'due'])) {
            $timeField = ($requestData['filter_timetype'] == 'created') ? 'created_at' : 'due';

            if (!empty($requestData['from'])) {
                $query->where($timeField, '>=', $requestData['from']);
            }
            if (!empty($requestData['to'])) {
                $query->where($timeField, '<=', $requestData['to'] . " 23:59:00");
            }
            $query->orderBy($timeField, 'desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }
    }

    /**
     * Apply Feedback Filter
     *
     * @param $query
     * @param array $requestData
     * @return bool
     */
    private function applyFeedbackFilter($query, array $requestData): bool
    {
        if (isset($requestData['feedback']) && $requestData['feedback'] !== 'false') {
            $query->where('ignore_feedback', '0')
                ->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
            return isset($requestData['count']) && $requestData['count'] === 'true';
        }
        return false;
    }

    /**
     * Apply Super Admin Filters
     *
     * @param $query
     * @param array $requestData
     * @return void
     */
    private function applySuperAdminFilters($query, array $requestData): void
    {
        if (!empty($requestData['expired_at'])) {
            $query->where('expired_at', '>=', $requestData['expired_at']);
        }

        if (!empty($requestData['will_expire_at'])) {
            $query->where('will_expire_at', '>=', $requestData['will_expire_at']);
        }

        if (isset($requestData['physical'])) {
            $query->where('customer_physical_type', $requestData['physical'])->where('ignore_physical', 0);
        }

        if (isset($requestData['phone'])) {
            $query->where('customer_phone_type', $requestData['phone']);
            if (isset($requestData['physical'])) {
                $query->where('ignore_physical_phone', 0);
            }
        }

        if (isset($requestData['flagged'])) {
            $query->where('flagged', $requestData['flagged'])->where('ignore_flagged', 0);
        }

        if (isset($requestData['distance']) && $requestData['distance'] == 'empty') {
            $query->whereDoesntHave('distance');
        }

        if (isset($requestData['salary']) && $requestData['salary'] == 'yes') {
            $query->whereDoesntHave('user.salaries');
        }

        if (!empty($requestData['consumer_type'])) {
            $query->whereHas('user.userMeta', function ($q) use ($requestData) {
                $q->where('consumer_type', $requestData['consumer_type']);
            });
        }

        if (isset($requestData['booking_type'])) {
            if ($requestData['booking_type'] == 'physical') {
                $query->where('customer_physical_type', 'yes');
            } elseif ($requestData['booking_type'] == 'phone') {
                $query->where('customer_phone_type', 'yes');
            }
        }
    }

    /**
     * Apply Consumer Filters
     *
     * @param $query
     * @param $consumerType
     * @return void
     */
    private function applyConsumerFilters($query, $consumerType): void
    {
        if ($consumerType == 'RWS') {
            $query->where('job_type', 'rws');
        } else {
            $query->where('job_type', 'unpaid');
        }
    }

    /**
     * Apply Emails filter
     *
     * @param $query
     * @param $emails
     * @param $column
     * @return void
     */
    private function filterByEmails($query, $emails, $column): void
    {
        $users = DB::table('users')->whereIn('email', (array)$emails)->get();
        if ($users->isNotEmpty()) {
            $query->whereIn($column, $users->pluck('id'));
        }
    }

    /**
     * Apply Translator Emails Filter
     * @param $query
     * @param $emails
     * @return void
     */
    private function filterByTranslatorEmails($query, $emails): void
    {
        $users = DB::table('users')->whereIn('email', (array)$emails)->get();
        if ($users->isNotEmpty()) {
            $translatorJobIds = DB::table('translator_job_rel')->whereNull('cancel_at')
                ->whereIn('user_id', $users->pluck('id'))
                ->pluck('job_id');
            $query->whereIn('id', $translatorJobIds);
        }
    }

}