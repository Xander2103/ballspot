<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class CreateLeagueRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'duration_days' => ['required', 'integer', 'in:1,3,7'],
            'rounds_per_day' => ['required', 'integer', 'in:1,3'],
        ];
    }
}
