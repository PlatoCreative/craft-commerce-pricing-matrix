<?php
/**
 * Craft Commerce Pricing Matrix plugin for Craft CMS 3.x
 *
 * Craft Commerce Pricing Matrices. Adds a custom field type that can be used to upload a pricing matrix for products in a CSV format.
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
use craft\commerce\elements\Product;

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
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://craftcms.com/docs/plugins/introduction
 *
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
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public $schemaVersion = '1.0.0';

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * CraftCommercePricingMatrix::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // // Register our site routes
        // Event::on(
        //     UrlManager::class,
        //     UrlManager::EVENT_REGISTER_SITE_URL_RULES,
        //     function (RegisterUrlRulesEvent $event) {
        //         $event->rules['siteActionTrigger1'] = 'craft-commerce-pricing-matrix/pricingmatrix';
        //     }
        // );

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

                $lineItem = $e->lineItem;
                $snapshot = $lineItem->snapshot;
                $options = $snapshot['options'];

                // Check this product has a pricing matrix associated with it, then get the product price
                if( self::$plugin->pricingmatrix->hasPricingMatrix($snapshot['productId']) ){

                    // Fetch the product record
                    $record = self::$plugin->pricingmatrix->getProductPriceRecord(
                        $snapshot['productId'], $options['width'], $options['height']
                    );

                    // Update line item properies
                    $lineItem->price = $record->price;
                    $lineItem->height = $record->height;
                    $lineItem->width = $record->width;
                }

                // Check if product is on sale here, and set on sale price
            }
        );

        // // Register our CP routes
        // Event::on(
        //     UrlManager::class,
        //     UrlManager::EVENT_REGISTER_CP_URL_RULES,
        //     function (RegisterUrlRulesEvent $event) {
        //         $event->rules['cpActionTrigger1'] = 'craft-commerce-pricing-matrix/pricingmatrix/do-something';
        //     }
        // );

        // Register our fields
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

        // // Do something after we're installed
        // Event::on(
        //     Plugins::class,
        //     Plugins::EVENT_AFTER_INSTALL_PLUGIN,
        //     function (PluginEvent $event) {
        //         if ($event->plugin === $this) {
        //             // We were just installed
        //         }
        //     }
        // );

/**
 * Logging in Craft involves using one of the following methods:
 *
 * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
 * Craft::info(): record a message that conveys some useful information.
 * Craft::warning(): record a warning message that indicates something unexpected has happened.
 * Craft::error(): record a fatal error that should be investigated as soon as possible.
 *
 * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
 *
 * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
 * the category to the method (prefixed with the fully qualified class name) where the constant appears.
 *
 * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
 * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
 *
 * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
 */
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

}
