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

namespace App\Jobs\Product;

use App\Libraries\MultiDB;
use App\Models\Product;
use App\Models\Subscription;
use App\Repositories\SubscriptionRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateSubscriptionPrices implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public function __construct(public Product $product)
    {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        MultiDB::setDb($this->product->company->db);

        // Find all subscriptions that contain this product
        $subscriptions = Subscription::query()
            ->where('company_id', $this->product->company_id)
            ->where(function ($query) {
                $query->where('product_ids', 'like', '%"' . $this->product->hashed_id . '"%')
                      ->orWhere('recurring_product_ids', 'like', '%"' . $this->product->hashed_id . '"%')
                      ->orWhere('optional_product_ids', 'like', '%"' . $this->product->hashed_id . '"%')
                      ->orWhere('optional_recurring_product_ids', 'like', '%"' . $this->product->hashed_id . '"%');
            })
            ->get();

        $subscription_repo = new SubscriptionRepository();

        foreach ($subscriptions as $subscription) {
            // Re-save the subscription to trigger price recalculation
            $subscription_repo->save([], $subscription);
        }
    }

    public function failed($exception = null)
    {
        if ($exception) {
            nlog("RecalculateSubscriptionPrices job failed: " . $exception->getMessage());
        }
    }
}
