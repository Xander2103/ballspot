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
        $maxMb = round(((int) config('ballspot.avatar.max_kb')) / 1024);
        $friendly = "Please choose a JPG, PNG or WebP image under {$maxMb}MB.";

        return [
            'avatar.required' => 'Please choose an image.',
            'avatar.file'     => $friendly,
            'avatar.image'    => $friendly,
            'avatar.mimes'    => $friendly,
            'avatar.max'      => $friendly,
        ];
    }
}
