<?php

namespace platocreative\craftcommercepricingmatrix\queue;

use craft\queue\BaseJob;
use platocreative\craftcommercepricingmatrix\CraftCommercePricingMatrix;

/**
 * Job that handles the saving of pricing matrices
 */
class SavePricingMatrixJob extends BaseJob
{
    public $variantId;

    public function execute($queue)
    {
        $variant = \craft\commerce\elements\Variant::find()->id($this->variantId)->one();
        CraftCommercePricingMatrix::$plugin->pricingmatrix->savePricingMatrix($variant);
    }

    protected function defaultDescription()
    {
        return 'Saving Pricing Matrices';
    }
}
