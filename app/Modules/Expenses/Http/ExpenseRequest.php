<?php

namespace App\Modules\Expenses\Http;

use App\Modules\Catalogs\Models\CatalogItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canRecordExpenses() === true;
    }

    public function rules(): array
    {
        return [
            'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('expenses.timezone'))->toDateString()],
            'description' => ['required', 'string', 'max:500'],
            'category' => ['required', 'string', 'max:100', function ($attribute, $value, $fail) {
                $item = CatalogItem::where('kind', 'category')->where('name_key', mb_strtolower($value))->first();
                if (! $item || (! $item->active && $this->route('expense')?->category_id !== $item->id)) {
                    $fail('Selecciona una categoría activa.');
                }
            }],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'version' => [$this->isMethod('PUT') ? 'required' : 'nullable', 'integer', 'min:1'],
            'petty_cash_account_id' => ['nullable', 'integer', 'exists:petty_cash_accounts,id'],
            'provider' => ['nullable', 'string', 'max:200'], 'tax_id' => ['nullable', 'regex:/^[0-9]{8,20}$/'],
            'receipt_type_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('kind', 'receipt_type')],
            'series' => ['nullable', 'string', 'max:30'], 'document_number' => ['nullable', 'string', 'max:50'], 'observation' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'spent_on.before_or_equal' => 'La fecha del gasto no puede estar en el futuro.',
            'amount.decimal' => 'El importe admite como máximo dos decimales.',
            'receipt.mimes' => 'El comprobante debe ser PDF, JPG o PNG.',
            'receipt.max' => 'El comprobante no debe superar 5 MB.',
        ];
    }
}
