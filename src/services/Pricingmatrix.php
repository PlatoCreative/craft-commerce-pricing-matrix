<?php
/**
 * Craft Commerce Pricing Matrix plugin for Craft CMS 3.x
 *
 * Craft Commerce Pricing Matrices. Adds a custom field type that can be used to upload a pricing matrix for products in a CSV format.
 *
 * @link      https://www.platocreative.co.nz/
 * @copyright Copyright (c) 2019 Josh Smith <josh.smith@platocreative.co.nz>
 */

namespace platocreative\craftcommercepricingmatrix\services;

use platocreative\craftcommercepricingmatrix\CraftCommercePricingMatrix;
use platocreative\craftcommercepricingmatrix\fields\Pricingmatrix as PricingMatrixField;
use platocreative\craftcommercepricingmatrix\records\Pricingmatrix as PricingMatrixRecord;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\models\Site;

use craft\commerce\Plugin as Commerce;
use craft\commerce\elements\Variant;
use craft\commerce\elements\Product;
use craft\commerce\models\LineItem;
use craft\commerce\events\LineItemEvent;

use yii\db\Query;

/**
 * Pricingmatrix Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Josh Smith <josh.smith@platocreative.co.nz>
 * @package   CraftCommercePricingMatrix
 * @since     1.4.0
 */
class Pricingmatrix extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Saves a Pricing Matrix from a commerce variant
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  Variant
     * @return void
     */
    public function savePricingMatrix(Variant $variant)
    {
        // Get the product and its fields
        $product = $variant->getProduct();
        $productFieldLayout = $product->getFieldLayout();
        $productFields = $productFieldLayout->getFields();
        $currentSite = Craft::$app->sites->getCurrentSite();

        // Loop product fields and insert pricing matrices from uploaded CSV's
        foreach ($productFields as $field) {
            if( ! $field instanceof PricingMatrixField ) continue;

            $isPromotional = (empty($field->promotionalPricing) ? '0' : '1');

            // Get all uploaded pricing matrices against this product field
            $pricingMatrixAsset = $product->{$field->handle}->all();

            // Delete pricing matrix information against this field if there's no assets.
            // The user might've removed an asset and re-saved.
            if( empty($pricingMatrixAsset) ){
                $this->deletePricingMatrix($product->id, $field->id, $currentSite->id);
                continue;
            }

            $widths = [];
            $heights = [];
            $pricing = [];
            $pricingMatrix = [];

            // Parse out the pricing grid
            foreach ($pricingMatrixAsset as $asset) {

                // Check if the CSV has been modified since the last time it was processed
                if( ! $this->isStale($asset, $product->id, $field->id, $currentSite->id) ) continue;

                // Parse out the inital grid
                $data = $asset->getContents();
                $data = explode("\n", $data);

                // Parse the header row
                $widths = explode(',',array_shift($data));
                $widths = array_slice($widths, 1);

                // Create the widths array and parse out csv values
                foreach ($data as $row) {
                    $pricingData = explode(',', $row);
                    $heights[] = array_shift($pricingData);
                    $pricing[] = $pricingData;
                }
            }

            // Generate a table matrix
            foreach ($widths as $col => $width) {
                foreach ($heights as $row => $height) {

                    // Don't include items with a blank price
                    $pricingVal = $pricing[$row][$col] ?? null;
                    if( empty(trim($pricingVal)) ) continue;

                    // Add the rest to the matrix
                    $pricingMatrix[] = [
                        $field->id,
                        $product->id,
                        $height,
                        $width,
                        $pricing[$row][$col],
                        $isPromotional,
                        $currentSite->id
                    ];
                }
            }

            // Don't process empty pricing matrices.
            if( empty($pricingMatrix) ) continue;

            // Determine the fields to use
            $pricingMatrixRecord = new PricingMatrixRecord();
            $fields = array_values(
                array_intersect($pricingMatrixRecord->attributes(), [
                    'fieldId','productId','height','width','price','isPromotional','siteId'
                ]
            ));

            // Delete pricing matrix from the database
            $this->deletePricingMatrix($product->id, $field->id, $currentSite->id);

            // Batch insert the new pricing matrix
            Craft::$app->db->createCommand()->batchInsert(PricingMatrixRecord::tableName(), $fields, $pricingMatrix)->execute();
        }
    }

    /**
     * Delete's pricing matrix data for the passed product field, and site
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  int
     * @param  int
     * @param  int
     * @return [type]
     */
    public function deletePricingMatrix(int $productId, int $fieldId, int $siteId = null)
    {
        if( is_null($siteId) ){
            $siteId = Craft::$app->sites->getCurrentSite()->id;
        }

        return PricingMatrixRecord::deleteAll([
            'fieldId' => $fieldId,
            'productId' => $productId,
            'siteId' => $siteId
        ]);
    }

    /**
     * Returns whether this product has an associated pricing matrix.
     * Optionally filters on a field and site Id.
     *
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  int
     * @param  int|null
     * @param  int|null
     * @return boolean
     */
    public function hasPricingMatrix(int $productId, int $fieldId = null, int $siteId = null)
    {
        if( is_null($siteId) ){
            $siteId = Craft::$app->sites->getCurrentSite()->id;
        }

        // Define the base where
        $where = [
            'productId' => $productId,
            'siteId' => $siteId
        ];

        if( !is_null($fieldId) ){
            $where['fieldId'] = $fieldId;
        }

        // Fetch one record matching this criteria
        $pricingMatrixRecord = PricingMatrixRecord::find()->where($where)->one();

        return !is_null($pricingMatrixRecord);
    }

    /**
     * Returns whether the product has a promotional pricing matrix
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  int
     * @param  int|null
     * @param  int|null
     * @return boolean
     */
    public function hasPromoPricingMatrix(int $productId, int $fieldId = null, int $siteId = null)
    {
        if( is_null($siteId) ){
            $siteId = Craft::$app->sites->getCurrentSite()->id;
        }

        // Define the base where
        $where = [
            'productId' => $productId,
            'siteId' => $siteId,
            'isPromotional' => '1'
        ];

        if( !is_null($fieldId) ){
            $where['fieldId'] = $fieldId;
        }

        // Fetch one record matching this criteria
        $pricingMatrixRecord = PricingMatrixRecord::find()->where($where)->one();

        return !is_null($pricingMatrixRecord);
    }

    /**
     * Returns a price for the passed product Id, width & height
     * Null is returned when an incomplete width or height is given.
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  int
     * @param  int
     * @param  int
     * @param  int
     * @param  int
     * @return float or null
     */
    public function getProductPrice(int $productId, int $width = null, int $height = null, int $fieldId = null, int $siteId = null) : ?float
    {
        $record = $this->getProductPriceRecord($productId, $width, $height, $fieldId, $siteId);
        return (empty($record) ? null : (float) $record->price);
    }

    /**
     * Returns a product pricing matrix record
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  int
     * @param  int
     * @param  int
     * @param  int
     * @param  int
     * @return float or null
     */
    public function getProductPriceRecord(int $productId, int $width = null, int $height = null, int $fieldId = null, int $siteId = null) : ?PricingMatrixRecord
    {
         if( is_null($width) || is_null($height) ) return null;

        if( is_null($siteId) ){
            $siteId = Craft::$app->sites->getCurrentSite()->id;
        }

        $where = [
            'productId' => $productId,
            'siteId' => $siteId,
            'isPromotional' => '0'
        ];

        // Set extra where properties
        if( !is_null($fieldId) ) $where['fieldId'] = $fieldId;

        return $this->_getProductPriceRecord($height, $width, $where);
    }

     /**
     * Returns a promotional price for the passed product Id, width & height
     * Null is returned when an incomplete width or height is given.
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  int
     * @param  int
     * @param  int
     * @param  int
     * @param  int
     * @return float or null
     */
    public function getProductPromoPrice(int $productId, int $width = null, int $height = null, int $fieldId = null, int $siteId = null) : ?float
    {
        $record = $this->getProductPricePromoRecord($productId, $width, $height, $fieldId, $siteId);
        return (empty($record) ? null : (float) $record->price);
    }

    /**
     * Returns a promotional product pricing matrix record
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  int
     * @param  int
     * @param  int
     * @param  int
     * @param  int
     * @return float or null
     */
    public function getProductPricePromoRecord(int $productId, int $width = null, int $height = null, int $fieldId = null, int $siteId = null) : ?PricingMatrixRecord
    {
         if( is_null($width) || is_null($height) ) return null;

        if( is_null($siteId) ){
            $siteId = Craft::$app->sites->getCurrentSite()->id;
        }

        $where = [
            'productId' => $productId,
            'siteId' => $siteId,
            'isPromotional' => '1'
        ];

        // Set extra where properties
        if( !is_null($fieldId) ) $where['fieldId'] = $fieldId;

        return $this->_getProductPriceRecord($height, $width, $where);
    }

    /**
     * Getter method for returning the product price record
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  int
     * @param  int
     * @param  array
     * @param  string
     * @return Product price record
     */
    protected function _getProductPriceRecord(int $height, int $width, array $where, string $round = '>=')
    {
        // Use this nifty ordering trick to fetch the rounded height and width value for the selected product.
        // This will use a full table scan, so might run into performance issues in the million(s) mark.
        // It's fast enough (and simple) to be optimised when it becomes a problem.
        return PricingMatrixRecord::find()
            ->where($where)
            ->andWhere([$round, 'height', $height])
            ->andWhere([$round, 'width', $width])
            ->orderBy("ABS(height - ($height + 1)), ABS(width - ($width + 1))")
        ->one();
    }

    /**
     * Returns max dimensions for the passed product Id.
     * If a width or height is specified, the nearest max bounds dimensions will be returned.
     */
    public function getMaxDimensions(int $productId, int $width = null, int $height = null, int $siteId = null): ?array
    {
        if( $width < $height ){ $height = null; } else { $width = null; }
        return $this->_getDimensions([
            'productId' => $productId,
        ], $width, $height, 'max', $siteId);
    }

    /**
     * Returns min dimensions for the passed product Id.
     * If a width or height is specified, the nearest min bounds dimensions will be returned.
     */
    public function getMinDimensions(int $productId, int $width = null, int $height = null, int $siteId = null): ?array
    {
        if( $width > $height ){ $height = null; } else { $width = null; }
        return $this->_getDimensions([
            'productId' => $productId,
        ], $width, $height, 'min', $siteId);
    }

    protected function _getDimensions(array $where, int $width = null, int $height = null, string $order = '', int $siteId = null): ?array
    {
        if( is_null($siteId) ){
            $where['siteId'] = Craft::$app->sites->getCurrentSite()->id;
        }

        $query = new Query();
        $query->select('height, width')
            ->from(PricingmatrixRecord::TABLE_NAME)
        ->where($where);

        switch ($order) {
            default:
            case 'max':
                $direction = 'DESC';
                $symbol = '>=';
                break;

            case 'min':
                $direction = 'ASC';
                $symbol = '<=';
                break;
        }

        if( !empty($width) ){
            $query->andWhere([$symbol, 'width', $width]);
            $query->addOrderBy('ABS(width -('.$width.'+1))');
        } else {
            $query->addOrderBy('width ' . $direction);
        }

        if( !empty($height) ){
            $query->andWhere([$symbol, 'height', $height]);
            $query->addOrderBy('ABS(height -('.$height.'+1))');
        } else {
            $query->addOrderBy('height ' . $direction);
        }

        $result = $query->limit(1)->one();
        return empty($result) ? null : $result;
    }

    /**
     * Returns the minimum standard pricing dimensions
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     */
    public function getMinStandardDimensions(int $productId, int $siteId = null): ?array
    {
        $where = ['isPromotional' => '0', 'productId' => $productId];
        return $this->_getDimensions($where, null, null, 'min', $siteId);
    }

    /*
     * Returns the maximum standard pricing dimensions
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     */
    public function getMaxStandardDimensions(int $productId, int $siteId = null): ?array
    {
        $where = ['isPromotional' => '0', 'productId' => $productId];
        return $this->_getDimensions($where, null, null, 'max', $siteId);
    }

    /**
     * Returns the minimum promo pricing dimensions
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     */
    public function getMinPromoDimensions(int $productId, int $siteId = null): ?array
    {
        $where = ['isPromotional' => '1', 'productId' => $productId];
        return $this->_getDimensions($where, null, null, 'min', $siteId);
    }

    /**
     * Returns the maximum promo pricing dimensions
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     */
    public function getMaxPromoDimensions(int $productId, int $siteId = null): ?array
    {
        $where = ['isPromotional' => '1', 'productId' => $productId];
        return $this->_getDimensions($where, null, null, 'max', $siteId);
    }

    /**
     * Returns whether the uploaded asset is stale
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  Asset
     * @param  int
     * @param  int
     * @param  int|null
     * @return boolean
     */
    public function isStale(Asset $pricingMatrixAsset, int $productId, int $fieldId, int $siteId = null)
    {
        if( is_null($siteId) ){
            $siteId = Craft::$app->sites->getCurrentSite()->id;
        }

        $lastUpdatedRecord = PricingMatrixRecord::find()->where([
            'productId' => $productId,
            'fieldId' => $fieldId,
            'siteId' => $siteId,
        ])->andWhere("dateCreated > '{$pricingMatrixAsset->dateCreated->format('Y-m-d H:i:s')}'")->one();

        return empty($lastUpdatedRecord);
    }

    /**
     * Handles population of line items event
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  LineItemEvent
     * @return void
     */
    public function handlePopulateLineItemEvent(LineItemEvent $e)
    {
        $lineItem = $e->lineItem;
        $snapshot = $lineItem->snapshot;
        $options = $snapshot['options'];

        $product = Commerce::getInstance()->getProducts()->getProductById($snapshot['productId']);
        if( empty($product) ) return;

        if($product->type == 'dualBlinds') {

            $primary  = $product->primaryProduct->one();
            $secondary  = $product->secondaryProduct->one();
            
            // Check this product has a pricing matrix associated with it, then get the product price

            /************************************************** PRIMARY PRODUCT **************************************************/
            $primaryHasPricingMatrix = $this->hasPricingMatrix($primary->id);
            if(! $primaryHasPricingMatrix) return;

            // Fetch the standard product record
            $primaryStandardPricingRecord = $this->getProductPriceRecord(
                $primary->id, $options['width'], $options['height']
            );

            // Fetch the promo product record
            $primaryPromoPricingRecord = $this->getProductPricePromoRecord(
                $primary->id, $options['width'], $options['height']
            );

            // Update line item properies
            if( !is_null($primaryStandardPricingRecord) ){
                $lineItem = $this->setLineItemDimensions($lineItem, $primaryStandardPricingRecord->width, $primaryStandardPricingRecord->height); // both primary and secondary will be the same
                $primaryLineItemPrice = $this->setLineItemPrice($lineItem, $primaryStandardPricingRecord->price);
            }

            // Check if product is on sale here, and set on sale price
            if( !is_null($primaryPromoPricingRecord) && $snapshot['onSale'] ){
                $lineItem = $this->setLineItemDimensions($lineItem, $primaryPromoPricingRecord->width, $primaryPromoPricingRecord->height); // both primary and secondary will be the same
                $primaryLineItemPromoPrice = $this->setLineItemPromoPrice($lineItem, $primaryStandardPricingRecord->price, $primaryPromoPricingRecord->price);
            }

            /************************************************** SECONDARY PRODUCT **************************************************/
            $secondaryHasPricingMatrix = $this->hasPricingMatrix($secondary->id);
            if(! $secondaryHasPricingMatrix) return;

            // Fetch the standard product record
            $secondaryStandardPricingRecord = $this->getProductPriceRecord(
                $secondary->id, $options['width'], $options['height']
            );

            // Fetch the promo product record
            $secondaryPromoPricingRecord = $this->getProductPricePromoRecord(
                $secondary->id, $options['width'], $options['height']
            );

            // Update line item properies
            if( !is_null($secondaryStandardPricingRecord) ){
                $lineItem = $this->setLineItemDimensions($lineItem, $secondaryStandardPricingRecord->width, $secondaryStandardPricingRecord->height); // both primary and secondary will be the same
                $secondaryLineItemPrice = $this->setLineItemPrice(
                    $lineItem, 
                    $primaryStandardPricingRecord->price + $secondaryStandardPricingRecord->price
                );
            }

            // Check if product is on sale here, and set on sale price
            if( !is_null($secondaryPromoPricingRecord) && isset($snapshot['onSale2']) && !is_null($primaryPromoPricingRecord) && $snapshot['onSale'] ){
                //BOTH ARE ON SALE
                $lineItem = $this->setLineItemDimensions($lineItem, $secondaryPromoPricingRecord->width, $secondaryPromoPricingRecord->height); // both primary and secondary will be the same
                $lineItem = $this->setLineItemPromoPrice($lineItem, $primaryStandardPricingRecord->price + $secondaryStandardPricingRecord->price, $primaryPromoPricingRecord->price + $secondaryPromoPricingRecord->price);

            } elseif ( !is_null($secondaryPromoPricingRecord) && isset($snapshot['onSale2']) && is_null($primaryPromoPricingRecord) && !$snapshot['onSale']){
                $lineItem = $this->setLineItemDimensions($lineItem, $secondaryPromoPricingRecord->width, $secondaryPromoPricingRecord->height); // both primary and secondary will be the same
                $lineItem = $this->setLineItemPromoPrice($lineItem, $primaryStandardPricingRecord->price + $secondaryStandardPricingRecord->price, $primaryStandardPricingRecord->price + $secondaryPromoPricingRecord->price);
            }

        } else {

            // Check this product has a pricing matrix associated with it, then get the product price
            $hasPricingMatrix = $this->hasPricingMatrix($snapshot['productId']);
            if(! $hasPricingMatrix) return;

            // Fetch the standard product record
            $standardPricingRecord = $this->getProductPriceRecord(
                $snapshot['productId'], $options['width'], $options['height']
            );

            // Fetch the promo product record
            $promoPricingRecord = $this->getProductPricePromoRecord(
                $snapshot['productId'], $options['width'], $options['height']
            );

            // Update line item properies
            if( !is_null($standardPricingRecord) ){
                $lineItem = $this->setLineItemDimensions($lineItem, $standardPricingRecord->width, $standardPricingRecord->height);
                $lineItem = $this->setLineItemPrice($lineItem, $standardPricingRecord->price);
            }

            // Check if product is on sale here, and set on sale price
            if( !is_null($promoPricingRecord) && $snapshot['onSale'] ){
                $lineItem = $this->setLineItemDimensions($lineItem, $promoPricingRecord->width, $promoPricingRecord->height);
                $lineItem = $this->setLineItemPromoPrice($lineItem, $standardPricingRecord->price, $promoPricingRecord->price);
            }

        }
    }

    /**
     * Sets dimension attributes on the passed line item
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  LineItem
     * @param  int
     * @param  int
     */
    public function setLineItemDimensions(LineItem $lineItem, int $width, int $height)
    {
        $lineItem->width = $width;
        $lineItem->height = $height;
        return $lineItem;
    }

    /**
     * Sets a standard price on the passed line item
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  LineItem
     * @param  float
     */
    public function setLineItemPrice(LineItem $lineItem, float $price)
    {
        $lineItem->price = $price;
        return $lineItem;
    }

    /**
     * Sets promo pricing on the passed line item
     * A standard and promo price is passed to calculate the difference
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @param  LineItem
     * @param  float
     * @param  float
     */
    public function setLineItemPromoPrice(LineItem $lineItem, float $standardPrice, float $promoPrice)
    {
        
        $lineItem->salePrice = $promoPrice;
        $lineItem->saleAmount = -($standardPrice - $promoPrice);
        return $lineItem;
    }
}
