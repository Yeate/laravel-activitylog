<?php

namespace Spatie\Activitylog;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;
use Request;
use Spatie\Activitylog\Exceptions\CouldNotLogActivity;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\IpAddress;
use Spatie\Activitylog\Models\RequestActivity;
class ActivityLogger
{
    use Macroable;

    /** @var \Illuminate\Auth\AuthManager */
    protected $auth;

    protected $logName = '';

    /** @var bool */
    protected $logEnabled;

    /** @var \Illuminate\Database\Eloquent\Model */
    protected $performedOn;

    /** @var \Illuminate\Database\Eloquent\Model */
    protected $causedBy;

    /** @var \Illuminate\Support\Collection */
    protected $properties;

    /** @var string */
    protected $authDriver;

    protected $isRecordIp;

    protected $isRecordRequest;

    public function __construct(AuthManager $auth, Repository $config)
    {
        $this->auth = $auth;

        $this->properties = collect();

        $this->authDriver = $config['activitylog']['default_auth_driver'] ?? $auth->getDefaultDriver();

        if (starts_with(app()->version(), '5.1')) {
            $this->causedBy = $auth->driver($this->authDriver)->user();
        } else {
            $this->causedBy = $auth->guard($this->authDriver)->user();
        }

        $this->logName = $config['activitylog']['default_log_name'];
        $this->isRecordIp = $config['activitylog']['record_ip'] ?? false;
        $this->isRecordRequest = $config['activitylog']['record_request'] ?? false;
        $this->logEnabled = $config['activitylog']['enabled'] ?? true;
    }

    public function performedOn(Model $model)
    {
        $this->performedOn = $model;

        return $this;
    }

    public function on(Model $model)
    {
        return $this->performedOn($model);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model|int|string $modelOrId
     *
     * @return $this
     */
    public function causedBy($modelOrId)
    {
        $model = $this->normalizeCauser($modelOrId);

        $this->causedBy = $model;

        return $this;
    }

    public function by($modelOrId)
    {
        return $this->causedBy($modelOrId);
    }

    /**
     * @param array|\Illuminate\Support\Collection $properties
     *
     * @return $this
     */
    public function withProperties($properties)
    {
        $this->properties = collect($properties);

        return $this;
    }

    /**
     * @param string $key
     * @param mixed $value
     *
     * @return $this
     */
    public function withProperty(string $key, $value)
    {
        $this->properties->put($key, $value);

        return $this;
    }

    public function useLog(string $logName)
    {
        $this->logName = $logName;

        return $this;
    }

    public function inLog(string $logName)
    {
        return $this->useLog($logName);
    }

    /**
     * @param string $description
     *
     * @return null|mixed
     */
    public function log(string $description)
    {
        if (! $this->logEnabled) {
            return;
        }
        $request = $this->RecordRequest();
        $activity = ActivitylogServiceProvider::getActivityModelInstance();

        if ($this->performedOn) {
            $activity->subject()->associate($this->performedOn);
        }

        if ($this->causedBy) {
            $activity->causer()->associate($this->causedBy);
        }

        $activity->properties = $this->properties;

        $activity->description = $this->replacePlaceholders($description, $activity);
        $activity->request_id=$request;
        $activity->log_name = $this->logName;
        $activity->activitylog_ip_id=$this->RecordIp();
        $activity->save();
        
        return $activity;
    }


    private function RecordIp(){
        if($this->isRecordIp){
            Request::setTrustedProxies(['127.0.0.1']);
            $ip = Request::getClientIp(); 
            $ipModel=IpAddress::where('ip',$ip)->first();
            if(empty($ipModel)){
                $ipInfo=file_get_contents('http://ip.taobao.com/service/getIpInfo.php?ip='.$ip);       
                $ipDetail=json_decode($ipInfo,true);
                $region = isset($ipDetail['data']['region'])?$ipDetail['data']['region']:'';
                $city = isset($ipDetail['data']['city'])?$ipDetail['data']['city']:'';
                $ipModel=new IpAddress();
                $ipModel->ip = $ip;
                $ipModel->address = $region.$city;
                $ipModel->save();
            }
            $id=$ipModel->id;

        }else{
            $id=0;
        }
        return $id;
    }


    private function RecordRequest(){
        if($this->isRecordRequest){
            $method=Request::method();
            $url=Request::path();
            $data=json_encode(Request::input());
            $request=new RequestActivity();
            $request -> request_method = $method;
            $request -> request_url = $url;
            $request -> request_data = $data;
            $request -> save();
            $id = $request->id;
        }else{
            $id=0;
        }
        return $id;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model|int|string $modelOrId
     *
     * @throws \Spatie\Activitylog\Exceptions\CouldNotLogActivity
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function normalizeCauser($modelOrId): Model
    {
        if ($modelOrId instanceof Model) {
            return $modelOrId;
        }

        if (starts_with(app()->version(), '5.1')) {
            $model = $this->auth->driver($this->authDriver)->getProvider()->retrieveById($modelOrId);
        } else {
            $model = $this->auth->guard($this->authDriver)->getProvider()->retrieveById($modelOrId);
        }

        if ($model) {
            return $model;
        }

        throw CouldNotLogActivity::couldNotDetermineUser($modelOrId);
    }

    protected function replacePlaceholders(string $description, Activity $activity): string
    {
        return preg_replace_callback('/:[a-z0-9._-]+/i', function ($match) use ($activity) {
            $match = $match[0];

            $attribute = (string) string($match)->between(':', '.');

            if (! in_array($attribute, ['subject', 'causer', 'properties'])) {
                return $match;
            }

            $propertyName = substr($match, strpos($match, '.') + 1);

            $attributeValue = $activity->$attribute;

            if (is_null($attributeValue)) {
                return $match;
            }

            $attributeValue = $attributeValue->toArray();

            return array_get($attributeValue, $propertyName, $match);
        }, $description);
    }
}
