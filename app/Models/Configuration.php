<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'query',
    'affiliate_tag',
    'cookie',
    'csrf_token',
    'affiliate_endpoint',
    'base_url',
    'max_items',
    'max_items_name',
])]
class Configuration extends Model
{
    use SoftDeletes;
}
