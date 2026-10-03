<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DriverUpload (a photo sent by the Driver App)
//  Location: app/Models/DriverUpload.php
//
//  kind: receipt | collection | pod | signature (custody). The file sits on the private
//  "trip_files" disk; entries (expense, cash, delivery) point at it by
//  the photo's uuid. See App\Http\Controllers\Driver\UploadController.
// ══════════════════════════════════════════════════════════════════

class DriverUpload extends Model
{
    use BelongsToCompany;

    public const KINDS = ['receipt', 'collection', 'pod', 'signature'];

    protected $fillable = ['uuid', 'company_id', 'driver_id', 'kind', 'path', 'size'];
}
