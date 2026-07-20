<?php

namespace App\Support\Documents;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class DocumentQuery
{
    /** @param array<string,list<string>> $rules
     * @return array<string,mixed>|DocumentActionResult
     */
    public static function validate(Request $request, array $rules): array|DocumentActionResult
    {
        $unknown = array_diff(array_keys($request->query()), array_keys($rules));
        if ($unknown !== []) {
            return new DocumentActionResult(['error_code' => 'validation', 'message' => 'Unknown query fields are not allowed.', 'details' => ['query' => array_values($unknown)]], 400);
        }$validator = Validator::make($request->query(), $rules);
        if ($validator->fails()) {
            return new DocumentActionResult(['error_code' => 'validation', 'message' => 'The request is invalid.', 'details' => ['fields' => $validator->errors()->toArray()]], 400);
        }

        return $validator->validated();
    }

    public static function empty(Request $request): ?DocumentActionResult
    {
        if ($request->query() !== [] || $request->all() !== []) {
            return new DocumentActionResult(['error_code' => 'validation', 'message' => 'No body or query fields are accepted.', 'details' => []], 400);
        }

        return null;
    }
}
