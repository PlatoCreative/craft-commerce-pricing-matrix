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

use Craft;
use craft\base\Component;

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
 * @since     1.0.0
 */
class Pricingmatrix extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * This function can literally be anything you want, and you can have as many service
     * functions as you want
     *
     * From any other plugin file, call it like this:
     *
     *     CraftCommercePricingMatrix::$plugin->pricingmatrix->exampleService()
     *
     * @return mixed
     */
    public function exampleService()
    {
        $result = 'something';

        return $result;
    }
}
