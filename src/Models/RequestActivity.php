<?php

namespace Spatie\Activitylog\Models;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RequestActivity extends Model
{
    protected $table;



    public function __construct(array $attributes = [])
    {
        $this->table = config('activitylog.request_table_name');

        parent::__construct($attributes);
    }
}
