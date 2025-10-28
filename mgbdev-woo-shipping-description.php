<?php
/**
 * Plugin Name: MGBDev WooCommerce Shipping Description
 * Plugin URI: https://github.com/mgbdev/woo-shipping-description
 * Description: Add custom descriptions to shipping methods displayed on cart and checkout pages (classic and block themes).
 * Version: 1.0.0
 * Author: MGBDev
 * Author URI: https://github.com/mgbdev
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 * Text Domain: mgbdev-woo-shipping-description
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace MGBDev\WSD;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declare HPOS compatibility
 * This plugin doesn't interact with order data, only shipping method settings
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
});

/**
 * Check if WooCommerce is active
 */
function mgbdev_shipping_desc_check_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\mgbdev_shipping_desc_missing_woocommerce_notice' );
		return false;
	}
	return true;
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\mgbdev_shipping_desc_check_woocommerce' );

/**
 * Display notice if WooCommerce is not active
 */
function mgbdev_shipping_desc_missing_woocommerce_notice() {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'WooCommerce Shipping Description requires WooCommerce to be installed and active.', 'mgbdev-woo-shipping-description' ); ?></p>
	</div>
	<?php
}

/**
 * Main plugin class
 */
class Shipping_Description {
	
	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'woocommerce_init', array( $this, 'init' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		// Register filter for all shipping methods
		$this->register_shipping_method_fields();
		
		// Display description on classic cart and checkout
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'display_classic_shipping_description' ), 10, 2 );
		
		// Display description on block cart and checkout using Store API
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'init_block_support' ), 10, 2 );
		add_filter( 'woocommerce_package_rates', array( $this, 'add_description_to_rates' ), 10, 2 );
	}

	/**
	 * Register custom fields for all shipping methods
	 */
	public function register_shipping_method_fields() {
		$shipping_methods = \WC()->shipping()->get_shipping_methods();
		
		foreach ( $shipping_methods as $method_id => $method ) {
			// Add field to instance form (for each shipping zone instance)
			add_filter( 'woocommerce_shipping_instance_form_fields_' . $method_id, array( $this, 'add_description_field' ) );
			
			// Add field to standalone settings (if any)
			add_filter( 'woocommerce_shipping_' . $method_id . '_instance_form_fields', array( $this, 'add_description_field' ) );
		}
	}

	/**
	 * Add description field to shipping method settings
	 *
	 * @param array $fields Form fields
	 * @return array Modified form fields
	 */
	public function add_description_field( $fields ) {
		$fields['description'] = array(
			'title'       => __( 'Description', 'mgbdev-woo-shipping-description' ),
			'type'        => 'textarea',
			'description' => __( 'This text will appear below the shipping method on cart and checkout pages.', 'mgbdev-woo-shipping-description' ),
			'default'     => '',
			'placeholder' => __( 'e.g., 3-5 business days', 'mgbdev-woo-shipping-description' ),
			'desc_tip'    => true,
			'css'         => 'width: 100%;',
		);

		return $fields;
	}

	/**
	 * Add description metadata to shipping rates
	 *
	 * @param array $rates    Available shipping rates
	 * @param array $package  Shipping package
	 * @return array Modified rates
	 */
	public function add_description_to_rates( $rates, $package ) {
		foreach ( $rates as $rate ) {
			$description = $this->get_rate_description( $rate );
			if ( ! empty( $description ) ) {
				$rate->description = $description;
			}
		}
		return $rates;
	}

	/**
	 * Display description on classic cart and checkout
	 *
	 * @param object $method Shipping method object
	 * @param int    $index  Index of the method
	 */
	public function display_classic_shipping_description( $method, $index ) {
		// Check if description is already set on the rate object
		if ( isset( $method->description ) && ! empty( $method->description ) ) {
			printf(
				'<div class="shipping-method-description">%s</div>',
				wp_kses_post( $method->description )
			);
			return;
		}

		// Fallback: try to get description directly
		$description = $this->get_rate_description( $method );
		
		if ( empty( $description ) ) {
			return;
		}

		printf(
			'<div class="shipping-method-description">%s</div>',
			wp_kses_post( $description )
		);
	}

	/**
	 * Initialize block support (placeholder for Store API extensions if needed)
	 *
	 * @param \WC_Order $order   Order object
	 * @param \WP_REST_Request $request Request object
	 */
	public function init_block_support( $order, $request ) {
		// This hook ensures block editor compatibility
		// The actual description is added via woocommerce_package_rates filter
	}

	/**
	 * Get description from shipping rate object
	 *
	 * @param object $rate Shipping rate object (WC_Shipping_Rate)
	 * @return string Description text
	 */
	private function get_rate_description( $rate ) {
		if ( ! $rate || ! is_object( $rate ) ) {
			return '';
		}

		// Get the rate ID - format is usually: method_id:instance_id
		$rate_id = isset( $rate->id ) ? $rate->id : '';
		
		if ( empty( $rate_id ) ) {
			return '';
		}

		// Parse the rate ID
		$parts = explode( ':', $rate_id );
		
		if ( count( $parts ) < 2 ) {
			return '';
		}

		$method_id   = $parts[0];
		$instance_id = isset( $parts[1] ) ? $parts[1] : '';

		// Try to get the shipping method instance
		$shipping_method = $this->get_shipping_method_instance( $method_id, $instance_id );

		// First, try to get description from the method instance
		if ( $shipping_method && method_exists( $shipping_method, 'get_instance_option' ) ) {
			$description = $shipping_method->get_instance_option( 'description' );
			if ( ! empty( $description ) ) {
				return $description;
			}
		}

		// Fallback: get directly from options table
		if ( ! empty( $instance_id ) ) {
			$instance_settings = get_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings', array() );
			
			if ( isset( $instance_settings['description'] ) && ! empty( $instance_settings['description'] ) ) {
				return $instance_settings['description'];
			}
		}

		return '';
	}

	/**
	 * Get shipping method instance by method ID and instance ID
	 *
	 * @param string $method_id   Shipping method ID
	 * @param string $instance_id Shipping method instance ID
	 * @return object|null Shipping method instance or null
	 */
	private function get_shipping_method_instance( $method_id, $instance_id ) {
		// Get all shipping zones
		$shipping_zones = \WC_Shipping_Zones::get_zones();
		
		// Check each zone for the method instance
		foreach ( $shipping_zones as $zone ) {
			if ( isset( $zone['shipping_methods'] ) ) {
				foreach ( $zone['shipping_methods'] as $method ) {
					if ( $method->id === $method_id && $method->instance_id == $instance_id ) {
						return $method;
					}
				}
			}
		}

		// Also check the "Rest of the World" zone (zone 0)
		$zone_0 = new \WC_Shipping_Zone( 0 );
		$methods = $zone_0->get_shipping_methods();
		
		foreach ( $methods as $method ) {
			if ( $method->id === $method_id && $method->instance_id == $instance_id ) {
				return $method;
			}
		}

		return null;
	}

	/**
	 * Enqueue styles and scripts
	 */
	public function enqueue_scripts() {
		// Only load on cart/checkout pages
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		wp_enqueue_style(
			'mgbdev-shipping-description',
			plugins_url( 'assets/css/shipping-description.css', __FILE__ ),
			array(),
			'1.0.0'
		);

		// Enqueue script for block checkout/cart support
		if ( function_exists( 'has_block' ) && ( has_block( 'woocommerce/cart' ) || has_block( 'woocommerce/checkout' ) ) ) {
			wp_enqueue_script(
				'mgbdev-shipping-description-blocks',
				plugins_url( 'assets/js/shipping-description-blocks.js', __FILE__ ),
				array( 'jquery' ),
				'1.0.0',
				true
			);

			// Pass shipping descriptions to JavaScript
			$this->localize_shipping_descriptions();
		}
	}

	/**
	 * Localize shipping descriptions for JavaScript
	 */
	private function localize_shipping_descriptions() {
		$descriptions = array();
		
		// Get all shipping zones and their methods
		$shipping_zones = \WC_Shipping_Zones::get_zones();
		
		foreach ( $shipping_zones as $zone ) {
			if ( isset( $zone['shipping_methods'] ) ) {
				foreach ( $zone['shipping_methods'] as $method ) {
					$rate_id = $method->id . ':' . $method->instance_id;
					$description = '';
					
					if ( method_exists( $method, 'get_instance_option' ) ) {
						$description = $method->get_instance_option( 'description' );
					}
					
					if ( ! empty( $description ) ) {
						$descriptions[ $rate_id ] = $description;
					}
				}
			}
		}

		// Also check the "Rest of the World" zone (zone 0)
		$zone_0 = new \WC_Shipping_Zone( 0 );
		$methods = $zone_0->get_shipping_methods();
		
		foreach ( $methods as $method ) {
			$rate_id = $method->id . ':' . $method->instance_id;
			$description = '';
			
			if ( method_exists( $method, 'get_instance_option' ) ) {
				$description = $method->get_instance_option( 'description' );
			}
			
			if ( ! empty( $description ) ) {
				$descriptions[ $rate_id ] = $description;
			}
		}

		wp_localize_script(
			'mgbdev-shipping-description-blocks',
			'mgbdevShippingDescriptions',
			array(
				'descriptions' => $descriptions,
			)
		);
	}
}

// Initialize plugin
new Shipping_Description();