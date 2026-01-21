<?php

namespace App\Jobs;

use Osiset\ShopifyApp\Contracts\Commands\Shop;
use Osiset\ShopifyApp\Contracts\Queries\Shop as QueriesShop;
use Osiset\ShopifyApp\Actions\CancelCurrentPlan;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

class AppUninstalledJob extends \Osiset\ShopifyApp\Messaging\Jobs\AppUninstalledJob
{
    public function handle(Shop $shopCommand, QueriesShop $shopQuery, CancelCurrentPlan $cancelCurrentPlanAction): bool
    {
        parent::handle($shopCommand, $shopQuery, $cancelCurrentPlanAction);

        // clear the llm generated at field
        $shop = User::withTrashed()->where('name', $this->domain)->first();
        if ($shop) {
            $shop->llm_generated_at = null;
            $shop->save();
        }

        // clear the llm.txt file from storage
        $filename = "llm/{$shop->id}.txt";
        if (Storage::exists($filename)) {
            Storage::delete($filename);
        }

        return true;
    }
}
