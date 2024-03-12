<?php
namespace DTApi\Helpers;

use Carbon\Carbon;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TeHelper
{
    public static function fetchLanguageFromJobId($id)
    {
        $language = Language::findOrFail($id);
        return $language1 = $language->language;
    }

    public static function getUsermeta($user_id, $key = false)
    {
        return $user = UserMeta::where('user_id', $user_id)->first()->$key;
        if (!$key)
            return $user->usermeta()->get()->all();
        else {
            $meta = $user->usermeta()->where('key', '=', $key)->get()->first();
            if ($meta)
                return $meta->value;
            else return '';
        }
    }

    public static function convertJobIdsInObjs($jobs_ids)
    {

        $jobs = array();
        foreach ($jobs_ids as $job_obj) {
            $jobs[] = Job::findOrFail($job_obj->id);
        }
        return $jobs;
    }

    public static function willExpireAt($due_time, $created_at)
    {
        // Parse the due time and creation time as Carbon objects
        $due_time = Carbon::parse($due_time);
        $created_at = Carbon::parse($created_at);

        // Calculate the time until expiration in minutes
        $timeToExpire = $due_time->diffInMinutes($created_at);

        // Determine the expiration time based on the calculated time until expiration
        if ($timeToExpire <= 90) {
            // If time until expiration is less than or equal to 90 minutes, set expiration time as the due time
            $time = $due_time;
        } elseif ($timeToExpire <= 1440) {
            // If time until expiration is less than or equal to 24 hours (1440 minutes), add 90 minutes to the creation time
            $time = $created_at->addMinutes(90);
        } elseif ($timeToExpire <= 4320) {
            // If time until expiration is less than or equal to 3 days (72 hours * 60 minutes/hour), add 16 hours to the creation time
            $time = $created_at->addHours(16);
        } else {
            // If time until expiration is greater than 3 days, subtract 48 hours from the due time
            $time = $due_time->subHours(48);
        }

        // Format the expiration time and return it
        return $time->format('Y-m-d H:i:s');
    }

}

