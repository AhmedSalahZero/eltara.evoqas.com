<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\CargoType;
use App\Models\VehicleType;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\LookupListController
//  Location: app/Http/Controllers/Office/LookupListController.php
//
//  The two "pick from a list, or add a new one" lists:
//    · Truck types  ( /office/vehicle-types ) — Heavy Truck, Tanker …
//    · Cargo / goods ( /office/cargo-types )  — Cement, Steel, Cheese …
//
//  store  → add a new one. Called from the truck form and the trip
//           form ("+ Add new" under the selector, answers with JSON so
//           the form stays open) and from Company settings.
//           Permission: vehicles.create (truck types) / trips.create (cargo).
//  update → rename or hide/show one (Company settings).
//           Permission: settings.edit.
//  Nothing is ever deleted, so old trucks and trips keep their type.
// ══════════════════════════════════════════════════════════════════

class LookupListController extends Controller
{
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        // Read from the route by name: Laravel hands route values to a method by position, so two of them can swap.
        $kind = (string) $request->route('kind');
        $class = $this->model($kind);
        $data = $this->validated($request, $class);

        $item = $class::query()->create($data + ['is_active' => true, 'sort' => 500]);
        Audit::record($kind.'_type.created', $item, ['after' => $data]);

        if ($request->wantsJson()) {
            return response()->json(['id' => $item->id, 'name' => $item->displayName()], 201);
        }

        return back()->with('success', __('common.saved'));
    }

    public function update(Request $request): RedirectResponse
    {
        $kind = (string) $request->route('kind');
        $class = $this->model($kind);
        $item = $class::query()->findOrFail((int) $request->route('id'));

        $item->fill($this->validated($request, $class, $item) + ['is_active' => $request->boolean('is_active', true)]);
        $changes = $item->getDirty();
        $item->save();

        if ($changes) {
            Audit::record($kind.'_type.updated', $item, ['after' => $changes]);
        }

        return back()->with('success', __('common.saved'));
    }

    // ── Helpers ────────────────────────────────────────────────────

    /** @return class-string<VehicleType|CargoType> */
    private function model(string $kind): string
    {
        return match ($kind) {
            'vehicle' => VehicleType::class,
            'cargo'   => CargoType::class,
            default   => abort(404),
        };
    }

    private function validated(Request $request, string $class, ?object $current = null): array
    {
        $table = (new $class)->getTable();
        $companyId = $request->user('web')->company_id;
        $request->merge(['name_ar' => trim((string) $request->input('name_ar')), 'name_en' => trim((string) $request->input('name_en')) ?: null]);

        return $request->validate([
            'name_ar' => ['required', 'string', 'max:80', Rule::unique($table)->where('company_id', $companyId)->ignore($current?->id)],
            'name_en' => ['nullable', 'string', 'max:80'],
        ], ['name_ar.unique' => __('errors.list_item_exists')]);
    }
}
