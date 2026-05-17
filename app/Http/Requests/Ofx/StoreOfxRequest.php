<?php

namespace App\Http\Requests\Ofx;

use Illuminate\Foundation\Http\FormRequest;

class StoreOfxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ofx_file' => ['required', 'file', 'mimes:ofx,txt,xml', 'max:5120'], // 5 MB
        ];
    }

    public function messages(): array
    {
        return [
            'ofx_file.required' => 'Selecione um arquivo OFX.',
            'ofx_file.file' => 'O campo deve ser um arquivo válido.',
            'ofx_file.mimes' => 'O arquivo deve estar no formato OFX, TXT ou XML.',
            'ofx_file.max' => 'O arquivo não pode ultrapassar 5 MB.',
        ];
    }
}
