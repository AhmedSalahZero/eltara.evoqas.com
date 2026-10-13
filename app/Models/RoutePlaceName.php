<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — RoutePlaceName (a "from" / "to" name on the route form)
//  Location: app/Models/RoutePlaceName.php
//
//  Arabic names are one list, shared by "from" and "to". English
//  names are the other list. The route still stores the four texts
//  itself (origin_ar, origin_en, destination_ar, destination_en).
// ══════════════════════════════════════════════════════════════════

class RoutePlaceName extends Model
{
    use BelongsToCompany;

    public const LANGUAGES = ['ar', 'en'];

    protected $fillable = ['company_id', 'language', 'name'];
}
