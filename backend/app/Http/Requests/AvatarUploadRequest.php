<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvatarUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $maxKb = (int) config('ballspot.avatar.max_kb');
        $mimes = implode(',', config('ballspot.avatar.mimes'));

        return [
            // `image` rejects SVG (getimagesize fails on it); `mimes` restricts
            // to a raster allow-list; `max` caps size in KB.
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:' . $mimes,
                'max:' . $maxKb,
            ],
        ];
    }

    public function messages(): array
    {
        $maxKb = (int) config('ballspot.avatar.max_kb');

        return [
            'avatar.required' => 'Please choose an image.',
            'avatar.image'    => 'The file must be a JPEG, PNG or WebP image.',
            'avatar.mimes'    => 'Only JPEG, PNG or WebP images are allowed (no SVG).',
            'avatar.max'      => 'The image is too large. Max size is ' . $maxKb . ' KB.',
        ];
    }
}
