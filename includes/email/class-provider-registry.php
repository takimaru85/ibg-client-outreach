<?php
/**
 * Registry of available email providers.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email;

use IBG\Outreach\Email\Providers\Email_Provider;
use IBG\Outreach\Email\Providers\SMTP_Provider;
use IBG\Outreach\Email\Providers\WP_Mail_Provider;
use IBG\Outreach\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Provider_Registry
 */
final class Provider_Registry {

	/**
	 * Registered providers keyed by id.
	 *
	 * @var array<string, Email_Provider>
	 */
	private array $providers = array();

	/**
	 * Whether built-in and extension providers have been loaded.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register a provider. Later registrations with the same id replace earlier ones.
	 *
	 * @param Email_Provider $provider Provider.
	 * @return void
	 */
	public function register( Email_Provider $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * All providers.
	 *
	 * @return array<string, Email_Provider>
	 */
	public function all(): array {
		$this->load();
		return $this->providers;
	}

	/**
	 * Get a provider by id.
	 *
	 * @param string $id Provider id.
	 * @return Email_Provider|null
	 */
	public function get( string $id ): ?Email_Provider {
		$this->load();
		return $this->providers[ $id ] ?? null;
	}

	/**
	 * The provider selected in settings, falling back to wp_mail when the
	 * selected one is unavailable (e.g. its extension was deactivated).
	 *
	 * @return Email_Provider
	 */
	public function get_active(): Email_Provider {
		$this->load();

		$id = (string) $this->settings->get( 'provider', WP_Mail_Provider::ID );

		return $this->providers[ $id ] ?? $this->providers[ WP_Mail_Provider::ID ];
	}

	/**
	 * Options for the provider select field: id => name.
	 *
	 * @return array<string, string>
	 */
	public function get_options(): array {
		$options = array();
		foreach ( $this->all() as $id => $provider ) {
			$options[ $id ] = $provider->get_name();
		}
		return $options;
	}

	/**
	 * Settings fields of every provider, each tagged with its provider id so
	 * the settings screen can group and toggle them.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all_settings_fields(): array {
		$fields = array();
		foreach ( $this->all() as $id => $provider ) {
			foreach ( $provider->get_settings_fields() as $key => $field ) {
				$field['provider']       = $id;
				$field['provider_label'] = $provider->get_name();
				$fields[ $key ]          = $field;
			}
		}
		return $fields;
	}

	/**
	 * Load built-in providers and let extensions register theirs.
	 *
	 * @return void
	 */
	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		$this->register( new WP_Mail_Provider() );
		$this->register( new SMTP_Provider( $this->settings ) );

		/**
		 * Register additional email providers.
		 *
		 * @param Provider_Registry $registry The registry.
		 */
		do_action( 'ibg_outreach_register_email_providers', $this );
	}
}
