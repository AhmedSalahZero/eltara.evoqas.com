<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Step 5 follow-up migration: "approved" and "assigned" apart
//  Location: database/migrations/2026_10_05_000002_client_request_assigned_status.php
//
//  A client request now has two separate answers:
//    approved  the office said yes, trucks not chosen yet
//    assigned  trucks chosen, one trip created per truck
//  Requests that were approved before this change already have their
//  trips, so they become "assigned". No table is changed.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_requests')->where('status', 'approved')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('trips')->whereColumn('trips.client_request_id', 'client_requests.id'))
            ->update(['status' => 'assigned']);
    }

    public function down(): void
    {
        DB::table('client_requests')->where('status', 'assigned')->update(['status' => 'approved']);
    }
};
