<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class MfaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'mfa_challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'size:6'],
        ];
    }
}
