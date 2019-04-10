/**
 * Craft Commerce Pricing Matrix plugin for Craft CMS
 *
 * Pricingmatrix Field JS
 *
 * @author    Josh Smith <josh.smith@platocreative.co.nz>
 * @copyright Copyright (c) 2019 Josh Smith <josh.smith@platocreative.co.nz>
 * @link      https://www.platocreative.co.nz/
 * @package   CraftCommercePricingMatrix
 * @since     1.0.0CraftCommercePricingMatrixPricingmatrix
 */

 ;(function ( $, window, document, undefined ) {

    var pluginName = "CraftCommercePricingMatrixPricingmatrix",
        defaults = {
        };

    // Plugin constructor
    function Plugin( element, options ) {
        this.element = element;

        this.options = $.extend( {}, defaults, options) ;

        this._defaults = defaults;
        this._name = pluginName;

        this.init();
    }

    Plugin.prototype = {

        init: function(id) {
            var _this = this;

            $(function () {

/* -- _this.options gives us access to the $jsonVars that our FieldType passed down to us */

            });
        }
    };

    // A really lightweight plugin wrapper around the constructor,
    // preventing against multiple instantiations
    $.fn[pluginName] = function ( options ) {
        return this.each(function () {
            if (!$.data(this, "plugin_" + pluginName)) {
                $.data(this, "plugin_" + pluginName,
                new Plugin( this, options ));
            }
        });
    };

})( jQuery, window, document );
