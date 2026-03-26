<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CargoCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'temperature_zone',
        'description',
    ];
}

