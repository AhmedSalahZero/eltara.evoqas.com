<?php

namespace App\Http\Requests\Office;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

// ══════════════════════════════════════════════════════════════════
//  El Tara — UpdatePermissionsRequest (the permission grid)
//  Location: app/Http/Requests/Office/UpdatePermissionsRequest.php
//
//  Saves the ticked permission keys for ONE named user, plus their
//  approval limit (Scope §5):
//    · unknown keys are dropped (only config/permissions.php counts);
//    · ticking "approve" without "view" on the same feature makes no
//      sense, so "view" is added automatically;
//    · whoever can approve wallet transfers must have an approval
//      limit (the most they may approve in one transfer).
// ══════════════════════════════════════════════════════════════════

class UpdatePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('users.edit') === true;
    }

    public function rules(): array
    {
        return [
            'permissions'    => ['present', 'array'],
            'permissions.*'  => ['string'],
            'approval_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $needs = config('permissions.needs_approval_limit', []);

            if (array_intersect($needs, $this->cleanPermissions()) && ! $this->filled('approval_limit')) {
                $validator->errors()->add('approval_limit', __('errors.approval_limit_needed'));
            }
        }];
    }

    /** The keys to save: real keys only, with "view" added where another action of the feature is ticked. */
    public function cleanPermissions(): array
    {
        $keys = Permissions::clean((array) $this->input('permissions', []));

        foreach ($keys as $key) {
            $view = strtok($key, '.').'.view';
            if (Permissions::exists($view)) {
                $keys[] = $view;
            }
        }

        return Permissions::clean($keys);
    }
}
