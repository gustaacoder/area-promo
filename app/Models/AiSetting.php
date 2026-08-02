<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'provider',
    'model',
    'system_prompt',
    'user_prompt_template',
    'temperature',
    'top_p',
    'max_output_tokens',
    'is_active',
])]
class AiSetting extends Model
{
    use SoftDeletes;
}
