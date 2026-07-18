<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAircraftHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $routeQuote = $this->route('quote');

        if (! $routeQuote) {
            return;
        }

        $this->merge([
            'quote_id' => $this->input('quote_id') ?: $routeQuote->id,
            'aircraft_id' => $this->input('aircraft_id') ?: $routeQuote->aircraft_id,
            'provider_id' => $this->input('provider_id') ?: $routeQuote->provider_id,
        ]);
    }

    public function rules(): array
    {
        return [
            'quote_id' => ['nullable', 'integer', 'min:1'],
            'aircraft_id' => ['required', 'integer', 'min:1', 'exists:aircraft,id'],
            'provider_id' => ['nullable', 'integer', 'min:1', 'exists:providers,id'],
            'match_id' => ['nullable'],
            'matched_option_id' => ['nullable'],
            'trip_type' => ['nullable', 'string', 'max:50'],
            'trip_label' => ['nullable', 'string', 'max:80'],
            'passengers' => ['nullable', 'integer', 'min:1'],
            'departure_date' => ['nullable', 'date_format:Y-m-d'],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'departure_datetime' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'start_datetime' => ['nullable', 'date'],
            'return_datetime' => ['nullable', 'date'],
            'legs' => ['nullable', 'array'],
            'legs.*.origin' => ['nullable', 'string', 'max:10'],
            'legs.*.destination' => ['nullable', 'string', 'max:10'],
            'legs.*.date' => ['nullable', 'date_format:Y-m-d'],
            'legs.*.time' => ['nullable', 'date_format:H:i'],
            'legs.*.departure_date' => ['nullable', 'date_format:Y-m-d'],
            'legs.*.departure_time' => ['nullable', 'date_format:H:i'],
            'legs.*.departure_datetime' => ['nullable', 'date'],
            'legs.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'legs.*.start_time' => ['nullable', 'date_format:H:i'],
            'legs.*.start_datetime' => ['nullable', 'date'],
            'legs.*.arrival_datetime' => ['nullable', 'date'],
        ];
    }
}
