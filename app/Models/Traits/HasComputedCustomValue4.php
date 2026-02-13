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

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasComputedCustomValue4
{
    /**
     * Get custom_value4 attribute.
     */
    protected function customValue4(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                if (! isset($attributes['line_items']) || ! $attributes['line_items']) {
                    return '';
                }

                $line_items = json_decode($attributes['line_items']);
                
                if (! is_iterable($line_items)) {
                    return '';
                }

                $custom_values = [];
                
                foreach ($line_items as $item) {
                    if (isset($item->custom_value1) && $item->custom_value1 !== '') {
                        $custom_values[] = (string) $item->custom_value1;
                    }
                }

                return implode(',', $custom_values);
            },
            set: function ($value) {
                // This attribute is calculated and should not be set directly
                // The value is always derived from line_items' custom_value1 values
                // We ignore any attempts to set this attribute
                return null;
            }
        )->shouldCache();
    }
}
