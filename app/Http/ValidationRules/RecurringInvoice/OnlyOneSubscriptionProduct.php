<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Http\ValidationRules\RecurringInvoice;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class OnlyOneSubscriptionProduct implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $itemsWithCustomValue1 = collect($value)
            ->filter(fn ($item) => !empty($item['custom_value1']))
            ->count();

        if ($itemsWithCustomValue1 > 1) {
            $fail('Only one subscription-product allowed per recurring invoice');
        }
    }
}
