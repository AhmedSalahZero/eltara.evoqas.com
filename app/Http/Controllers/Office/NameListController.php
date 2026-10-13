<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\InsuranceCompany;
use App\Models\RoutePlaceName;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\NameListController
//  Location: app/Http/Controllers/Office/NameListController.php
//
//  Adds one name to a selector while the form stays open
//  (the same idea as LookupListController, for a single name):
//    POST /office/insurance-companies   vehicles.create
//    POST /office/route-places          customers.create
//  Answers with JSON so the new name can be selected at once.
//  A repeated name in the same company is refused.
// ══════════════════════════════════════════════════════════════════

class NameListController extends Controller
{
    public function storeInsurance(Request $request): JsonResponse
    {
        $companyId = $request->user('web')->company_id;
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('insurance_companies', 'name')->where('company_id', $companyId)],
        ], ['name.unique' => __('errors.list_item_exists')]);

        $item = InsuranceCompany::query()->create([
            'company_id' => $companyId,
            'name'       => trim($data['name']),
        ]);
        Audit::record('insurance_company.created', $item, ['after' => ['name' => $item->name]]);

        return response()->json(['id' => $item->id, 'name' => $item->name], 201);
    }

    public function storePlace(Request $request): JsonResponse
    {
        $companyId = $request->user('web')->company_id;
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'language' => ['required', Rule::in(RoutePlaceName::LANGUAGES)],
            'name'     => ['required', 'string', 'max:80', Rule::unique('route_place_names', 'name')->where(fn ($q) => $q->where('company_id', $companyId)->where('language', $request->input('language')))],
        ], ['name.unique' => __('errors.list_item_exists')]);

        $item = RoutePlaceName::query()->create([
            'company_id' => $companyId,
            'language'   => $data['language'],
            'name'       => trim($data['name']),
        ]);
        Audit::record('route_place.created', $item, ['after' => ['language' => $item->language, 'name' => $item->name]]);

        return response()->json(['id' => $item->id, 'name' => $item->name, 'language' => $item->language], 201);
    }
}
