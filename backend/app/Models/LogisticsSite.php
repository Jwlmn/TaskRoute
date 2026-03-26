<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LogisticsSite extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_no',
        'name',
        'site_type',
        'contact_person',
        'contact_phone',
        'address',
        'lng',
        'lat',
        'status',
    ];
}

