<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\DriverUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver\UploadController ( POST /driver/api/uploads )
//  Location: app/Http/Controllers/Driver/UploadController.php
//
//  Receives ONE photo from the phone (receipt, cash receipt or the
//  stamped delivery note). The phone already shrank it. Idempotent:
//  the same photo uuid sent twice (a bad signal, a retry) keeps the
//  first file and answers "ok" again.
//
//  Fresh-photo rule: the phone also sends when the photo file was made
//  (taken_at) and when the driver saved it (captured_at). A document photo
//  made more than a few minutes before it was saved is refused (422), so
//  the driver must retake it with the camera.
// ══════════════════════════════════════════════════════════════════

class UploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'kind' => ['required', 'in:'.implode(',', DriverUpload::KINDS)],
            'file' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:'.(int) config('eltara.uploads.max_kb', 6144)],
            // When the picture file was made, and when the driver saved it in the app (both from the phone).
            'taken_at'    => ['nullable', 'date'],
            'captured_at' => ['nullable', 'date'],
        ]);

        // A document photo must be fresh (not an old receipt picked from the gallery). The finger signature
        // is drawn on screen, so it has no "taken" time and is not checked.
        if ($data['kind'] !== 'signature' && ! empty($data['taken_at']) && ! empty($data['captured_at'])) {
            $ageMinutes = Carbon::parse($data['taken_at'])->diffInMinutes(Carbon::parse($data['captured_at']), false);

            if ($ageMinutes > (int) config('eltara.uploads.photo_max_age_minutes', 15)) {
                throw ValidationException::withMessages(['file' => __('trips.driver_photo_old')]);
            }
        }

        $driver = $request->user('driver');

        $existing = DriverUpload::query()->withoutGlobalScopes()->where('uuid', $data['uuid'])->first();
        if ($existing) {
            // Another driver's photo id is never revealed or reused.
            abort_if($existing->driver_id !== $driver->id, 403);

            return response()->json(['ok' => true, 'uuid' => $existing->uuid]);
        }

        $file = $request->file('file');
        $path = $file->storeAs("driver-uploads/{$driver->id}", $data['uuid'].'.'.($file->guessExtension() ?: 'jpg'), 'trip_files');

        DriverUpload::query()->create([
            'uuid'       => $data['uuid'],
            'company_id' => $driver->company_id,
            'driver_id'  => $driver->id,
            'kind'       => $data['kind'],
            'path'       => $path,
            'size'       => (int) $file->getSize(),
        ]);

        return response()->json(['ok' => true, 'uuid' => $data['uuid']]);
    }
}
