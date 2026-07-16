<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

trait RejectsUnknownFields
{
    /** @param array<int, string> $allowed */
    private function rejectUnknownFields(Validator $validator, array $allowed): void
    {
        $unknown = array_diff(array_keys($this->all()), $allowed);
        if ($unknown !== []) {
            $validator->after(fn (Validator $validator) => $validator->errors()->add('body', 'Unknown fields are not allowed: '.implode(', ', $unknown)));
        }
    }
}
