<?php

namespace DTApi\Repository;

use DTApi\Models\Company;
use DTApi\Models\Department;
use DTApi\Models\Type;
use DTApi\Models\UsersBlacklist;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use DTApi\Models\User;
use DTApi\Models\Town;
use DTApi\Models\UserMeta;
use DTApi\Models\UserTowns;
use DTApi\Events\JobWasCreated;
use DTApi\Models\UserLanguages;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\FirePHPHandler;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class UserRepository extends BaseRepository
{

    protected $model;
    protected $logger;

    /**
     * @param User $model
     */
    function __construct(User $model)
    {
        parent::__construct($model);
//        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function createOrUpdate($id = null, $request)
    {
        $model = is_null($id) ? new User : User::findOrFail($id);
        
        // Fill user attributes
        $model->fill([
            'user_type' => $request['role'],
            'name' => $request['name'],
            'company_id' => $request['company_id'] ?: 0,
            'department_id' => $request['department_id'] ?: 0,
            'email' => $request['email'],
            'dob_or_orgid' => $request['dob_or_orgid'],
            'phone' => $request['phone'],
            'mobile' => $request['mobile'],
        ]);
        
        // Set password if provided or creating a new user
        if (!$id || ($id && $request['password'])) {
            $model->password = bcrypt($request['password']);
        }
        
        // Save the user
        $model->save();
        
        // Attach role to the user
        $model->detachAllRoles();
        $model->attachRole($request['role']);
        
        // Handle customer or translator details based on role
        if ($request['role'] == env('CUSTOMER_ROLE_ID')) {
            // Handle customer specific details
            if ($request['consumer_type'] == 'paid' && empty($request['company_id'])) {
                $type = Type::where('code', 'paid')->first();
                $company = Company::create([
                    'name' => $request['name'],
                    'type_id' => $type->id,
                    'additional_info' => 'Created automatically for user ' . $model->id
                ]);
                $department = Department::create([
                    'name' => $request['name'],
                    'company_id' => $company->id,
                    'additional_info' => 'Created automatically for user ' . $model->id
                ]);

                $model->company_id = $company->id;
                $model->department_id = $department->id;
                $model->save();
            }

            // Save or update user meta
            $user_meta = UserMeta::updateOrCreate(['user_id' => $model->id], [
                'consumer_type' => $request['consumer_type'],
                'customer_type' => $request['customer_type'],
                // Add other fields here
            ]);

            // Handle translator blacklist
            $this->handleTranslatorBlacklist($model, $request);
        } elseif ($request['role'] == env('TRANSLATOR_ROLE_ID')) {
            // Handle translator specific details
            // Save or update user meta
            $user_meta = UserMeta::updateOrCreate(['user_id' => $model->id], [
                'translator_type' => $request['translator_type'],
                'worked_for' => $request['worked_for'],
                // Add other fields here
            ]);

            // Handle user languages
            // Use the provided logic to handle user languages
        }
        
        // Handle new towns
        if ($request['new_towns']) {
            $towns = new Town;
            $towns->townname = $request['new_towns'];
            $towns->save();
            $newTownsId = $towns->id;
        }

        // Handle user towns projects
        $townidUpdated = [];
        if ($request['user_towns_projects']) {
            $del = DB::table('user_towns')->where('user_id', '=', $model->id)->delete();
            foreach ($request['user_towns_projects'] as $townId) {
                $userTown = new UserTowns();
                $already_exit = $userTown::townExist($model->id, $townId);
                if ($already_exit == 0) {
                    $userTown->user_id = $model->id;
                    $userTown->town_id = $townId;
                    $userTown->save();
                }
                $townidUpdated[] = $townId;
            }
        }

        // Handle user status
        $this->handleUserStatus($model, $request['status']);

        return $model ?: false;
    }

    private function handleTranslatorBlacklist($model, $request)
    {
        // Handle translator blacklist
        // Use the provided logic to handle translator blacklist
    }

    private function handleUserStatus($model, $status)
    {
        // Handle user status
        $model->status = $status;
        $model->save();
    }


    public function enable($id)
    {
        $user = User::findOrFail($id);
        $user->status = '1';
        $user->save();

    }

    public function disable($id)
    {
        $user = User::findOrFail($id);
        $user->status = '0';
        $user->save();

    }

    public function getTranslators()
    {
        return User::where('user_type', 2)->get();
    }
    
}