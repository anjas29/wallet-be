<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'icon',
        'color',
    ];
}
