<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class SubmitGuessRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'guess_x_ratio' => ['required', 'numeric', 'between:0,1'],
            'guess_y_ratio' => ['required', 'numeric', 'between:0,1'],
        ];
    }
}
