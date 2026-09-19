<?php
/**
 * Settings page (WordPress Settings API, tabbed).
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Pages;

use IBG\Outreach\Capabilities;
use IBG\Outreach\Email\Provider_Registry;
use IBG\Outreach\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings_Page
 */
final class Settings_Page extends Abstract_Page {

	public const SLUG         = 'ibg-outreach-settings';
	public const OPTION_GROUP = 'ibg_outreach_settings_group';

	/** @inheritDoc */
	public function get_slug(): string {
		return self::SLUG;
	}

	/** @inheritDoc */
	public function get_page_title(): string {
		return __( 'IBG Outreach Settings', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_menu_title(): string {
		return __( 'Settings', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_capability(): string {
		return Capabilities::MANAGE_SETTINGS;
	}

	/** @inheritDoc */
	public function get_position(): int {
		return 100;
	}

	/** @inheritDoc */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		// options.php requires manage_options by default; map it to our capability.
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, static fn(): string => Capabilities::MANAGE_SETTINGS );
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->plugin->get( 'settings' ), 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/** @inheritDoc */
	public function render(): void {
		$this->require_capability();

		/** @var Settings $settings */
		$settings = $this->plugin->get( 'settings' );
		/** @var Provider_Registry $providers */
		$providers = $this->plugin->get( 'providers' );

		$sections    = $settings->get_sections();
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $sections[ $current_tab ] ) ) {
			$current_tab = (string) array_key_first( $sections );
		}

		$this->render_view(
			'settings',
			array(
				'page'        => $this,
				'sections'    => $sections,
				'current_tab' => $current_tab,
				'values'      => $settings->all(),
				'providers'   => $providers->all(),
				'active_id'   => $providers->get_active()->get_id(),
			)
		);
	}

	/**
	 * Render one field's control.
	 *
	 * @param string $key   Setting key.
	 * @param array  $field Field definition.
	 * @param mixed  $value Current value.
	 * @return void
	 */
	public function render_field( string $key, array $field, mixed $value ): void {
		$name = Settings::OPTION . '[' . $key . ']';
		$id   = 'ibg-setting-' . $key;
		$type = (string) ( $field['type'] ?? 'text' );

		switch ( $type ) {
			case 'bool':
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html( (string) ( $field['checkbox'] ?? '' ) )
				);
				break;

			case 'int':
				printf(
					'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" step="1">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) (int) $value ),
					esc_attr( (string) ( $field['min'] ?? 0 ) ),
					esc_attr( (string) ( $field['max'] ?? '' ) )
				);
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( (array) ( $field['options'] ?? array() ) as $option_value => $label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( (string) $option_value ),
						selected( (string) $value, (string) $option_value, false ),
						esc_html( (string) $label )
					);
				}
				echo '</select>';
				break;

			case 'textarea':
			case 'html':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text code">%4$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) ( $field['rows'] ?? 4 ),
					esc_textarea( (string) $value )
				);
				break;

			case 'email':
			case 'url':
			case 'text':
			default:
				printf(
					'<input type="%1$s" class="regular-text" id="%2$s" name="%3$s" value="%4$s" %5$s>',
					esc_attr( in_array( $type, array( 'email', 'url', 'text' ), true ) ? $type : 'text' ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					! empty( $field['required'] ) ? 'aria-required="true"' : ''
				);
				break;
		}

		if ( ! empty( $field['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( (string) $field['description'] ) );
		}
	}
}
