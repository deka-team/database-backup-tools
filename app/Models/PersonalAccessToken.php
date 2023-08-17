<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Sanctum\PersonalAccessToken as Model;

class PersonalAccessToken extends Model
{
    use HasUlids;
}