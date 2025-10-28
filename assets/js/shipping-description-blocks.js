/**
 * WooCommerce Shipping Description - Block Support
 * Adds shipping descriptions to WooCommerce Cart and Checkout blocks
 */

(function($) {
    'use strict';

    // Check if descriptions data is available
    if (typeof mgbdevShippingDescriptions === 'undefined') {
        return;
    }

    var descriptions = mgbdevShippingDescriptions.descriptions || {};

    /**
     * Add description to a shipping option element
     */
    function addDescriptionToShippingOption(element) {
        var $element = $(element);
        var $input = $element.find('input[type="radio"][name*="radio-control-"]');
        
        if ($input.length === 0) {
            return;
        }

        var rateId = $input.val();
        
        if (!rateId || !descriptions[rateId]) {
            return;
        }

        // Check if description already exists
        if ($element.find('.shipping-method-description').length > 0) {
            return;
        }

        // Create and append description
        var $description = $('<div>', {
            'class': 'shipping-method-description',
            'html': descriptions[rateId]
        });

        // Find the best place to insert the description
        var $label = $element.find('.wc-block-components-radio-control__label');
        if ($label.length > 0) {
            $label.after($description);
        } else {
            $element.append($description);
        }
    }

    /**
     * Process all shipping options on the page
     */
    function processShippingOptions() {
        // For WooCommerce blocks
        $('.wc-block-components-radio-control-accordion-option, .wc-block-components-radio-control__option').each(function() {
            addDescriptionToShippingOption(this);
        });

        // Additional selector for cart blocks
        $('.wc-block-components-shipping-rates-control__package .wc-block-components-radio-control__option').each(function() {
            addDescriptionToShippingOption(this);
        });
    }

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Initial processing
        processShippingOptions();

        // Watch for dynamic changes (when shipping options are updated)
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    processShippingOptions();
                }
            });
        });

        // Observe the shipping options container
        var targetNodes = document.querySelectorAll(
            '.wc-block-components-shipping-rates-control, ' +
            '.wc-block-components-totals-shipping, ' +
            '.wc-block-checkout__shipping-option'
        );

        targetNodes.forEach(function(node) {
            observer.observe(node, {
                childList: true,
                subtree: true
            });
        });

        // Also listen to WooCommerce block events
        $(document.body).on('updated_checkout updated_cart_totals', function() {
            setTimeout(processShippingOptions, 100);
        });

        // Listen to shipping method changes
        $(document.body).on('change', 'input[name*="radio-control-"]', function() {
            setTimeout(processShippingOptions, 100);
        });
    });

})(jQuery);

