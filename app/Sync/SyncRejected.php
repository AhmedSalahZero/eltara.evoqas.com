<?php

namespace App\Sync;

use RuntimeException;

// ══════════════════════════════════════════════════════════════════
//  El Tara — SyncRejected
//  Location: app/Sync/SyncRejected.php
//
//  Thrown by a SyncHandler to refuse one offline entry for a business
//  reason. The message is shown to the driver on the phone, so write
//  it in their language: throw new SyncRejected(__('…')).
// ══════════════════════════════════════════════════════════════════

class SyncRejected extends RuntimeException {}
