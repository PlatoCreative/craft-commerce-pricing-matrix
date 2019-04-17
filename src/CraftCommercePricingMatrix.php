<?php
/**
 * Craft Commerce Pricing Matrix plugin for Craft CMS 3.x
 *
 * Craft Commerce Pricing Matrices.
 * Adds a custom field type that can be used to upload a pricing matrix for products in a CSV format.
 *
 * @link      https://www.platocreative.co.nz/
 * @copyright Copyright (c) 2019 Josh Smith <josh.smith@platocreative.co.nz>
 */

namespace platocreative\craftcommercepricingmatrix;

use platocreative\craftcommercepricingmatrix\services\Pricingmatrix as PricingmatrixService;
use platocreative\craftcommercepricingmatrix\fields\Pricingmatrix as PricingmatrixField;
use platocreative\craftcommercepricingmatrix\queue\SavePricingMatrixJob;
use craft\commerce\services\Variants;
use craft\commerce\events\LineItemEvent;
use craft\commerce\services\LineItems;

use Craft;
use craft\base\Plugin;
use craft\services\Elements;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\helpers\Assets;
use craft\web\UrlManager;
use craft\services\Fields;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterAssetFileKindsEvent;

use yii\base\Event;

/**
 * @author    Josh Smith <josh.smith@platocreative.co.nz>
 * @package   CraftCommercePricingMatrix
 * @since     1.0.0
 *
 * @property  PricingmatrixService $pricingmatrix
 */
class CraftCommercePricingMatrix extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * CraftCommercePricingMatrix::$plugin
     *
     * @var CraftCommercePricingMatrix
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $schemaVersion = '1.0.0';

    /**
     * @event LineItemEvent This event is raised before the pricing matrix plugin populates a line item with a price.
     */
    const EVENT_BEFORE_PRICING_MATRIX_LINE_ITEM_LOOKUP = 'beforePricingMatrixLineItemLookup';

    /**
     * @event LineItemEvent This event is raised after the pricing matrix plugin populates a line item with a price.
     */
    const EVENT_AFTER_PRICING_MATRIX_LINE_ITEM_LOOKUP = 'afterPricingMatrixLineItemLookup';

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * CraftCommercePricingMatrix::$plugin
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Setup global plugin event handlers
        $this->registerEventHandlers();

        Craft::info(
            Craft::t(
                'craft-commerce-pricing-matrix',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * Method for registering plugin event handlers
     * @author Josh Smith <josh.smith@platocreative.co.nz>
     * @return void
     */
    protected function registerEventHandlers()
    {
        // Register CSV files as an allowed file type
        Event::on(
            Assets::class,
            Assets::EVENT_REGISTER_FILE_KINDS,
            function (RegisterAssetFileKindsEvent $event) {
                $event->fileKinds['csv'] = [
                    'label' => 'CSV',
                    'extensions' => ['csv']
                ];
            }
        );

        // Register a line item population event to handle pricing matrix lookups.
        Event::on(
            LineItems::class,
            LineItems::EVENT_POPULATE_LINE_ITEM,
            function(LineItemEvent $e) {

                // Create a custom event that's fired before a pricing matrix is looked up
                $beforeMatrixLineItemLookupEvent = new LineItemEvent([
                    'lineItem' => $e->lineItem,
                    'isNew' => !$e->lineItem->id
                ]);

                // Allow plugins to modify the line item before pricing is applied
                if ($this->hasEventHandlers(self::EVENT_BEFORE_PRICING_MATRIX_LINE_ITEM_LOOKUP)) {
                    $this->trigger(self::EVENT_BEFORE_PRICING_MATRIX_LINE_ITEM_LOOKUP, $beforeMatrixLineItemLookupEvent);
                }

                // Exit this event handler if the event has been cancelled
                if (!$beforeMatrixLineItemLookupEvent->isValid) return;

                // Offload the event handler to the service
                self::$plugin->pricingmatrix->handlePopulateLineItemEvent($e);

                // Allow plugins to modify the line item after pricing is applied
                if ($this->hasEventHandlers(self::EVENT_AFTER_PRICING_MATRIX_LINE_ITEM_LOOKUP)) {
                    $this->trigger(self::EVENT_AFTER_PRICING_MATRIX_LINE_ITEM_LOOKUP, new LineItemEvent([
                        'lineItem' => $e->lineItem,
                        'isNew' => !$e->lineItem->id
                    ]));
                }
            }
        );

        // Register the custom pricing matrix field
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = PricingmatrixField::class;
            }
        );

        // Detect when commerce products are saved and save the pricing matrix
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function(Event $event) {
                if( is_a($event->element, "craft\\commerce\\elements\\Variant") ) {
                    Craft::$app->queue->push(new SavePricingMatrixJob([
                        'variantId' => $event->element->id,
                    ]));
                }
            }
        );
    }
}
