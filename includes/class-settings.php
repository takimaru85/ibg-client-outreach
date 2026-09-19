<?php
/**
 * Plugin settings: defaults, field schema and sanitisation.
 *
 * All settings live in a single option array. The field schema returned by
 * get_sections() is the single source of truth for both the Settings page
 * (rendering) and the sanitize callback (validation), so a field can never be
 * rendered without a matching sanitiser.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
final class Settings {

	public const OPTION = 'ibg_outreach_settings';

	/**
	 * Merged (defaults + stored) settings cache.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Callbacks that supply select options at render time.
	 *
	 * @var array<string, callable>
	 */
	private array $dynamic_options = array();

	/**
	 * Register a callback that provides the options for a select field.
	 *
	 * @param string   $key      Setting key.
	 * @param callable $callback Returns array<string, string> of value => label.
	 * @return void
	 */
	public function set_dynamic_options( string $key, callable $callback ): void {
		$this->dynamic_options[ $key ] = $callback;
	}

	/**
	 * Default values.
	 *
	 * Email-content defaults are intentionally plain strings: they are
	 * user-editable content, not UI, and this method may be called before
	 * translations are loaded.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			// General.
			'business_name'            => (string) get_bloginfo( 'name' ),
			'business_website'         => (string) home_url( '/' ),
			'from_name'                => (string) get_bloginfo( 'name' ),
			'from_email'               => (string) get_option( 'admin_email', '' ),
			'reply_to'                 => '',
			// Email delivery.
			'provider'                 => 'wp_mail',
			'batch_size'               => 25,
			'batch_delay'              => 60,
			'max_retries'              => 3,
			'retry_delay'              => 15,
			// Compliance.
			'business_address'         => '',
			'consent_statement'        => 'You are receiving this email because we have a business contact relationship or you requested information about our services.',
			'unsubscribe_text'         => "Don't want to receive these emails anymore?",
			'privacy_policy_url'       => (string) get_privacy_policy_url(),
			'email_footer'             => "{{business_name}}\n{{business_website}}\n\n{{consent_statement}}\n\n{{unsubscribe_text}} {{unsubscribe_link}}\n\n{{business_address}}",
			// Privacy & data.
			'track_opens'              => false,
			'track_clicks'             => false,
			'log_retention_days'       => 90,
			'queue_retention_days'     => 30,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * All settings, merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = array_merge( $this->defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value when the key is unknown.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Persist a set of values (merged over current values, sanitised).
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return void
	 */
	public function update( array $values ): void {
		$clean = $this->sanitize( $values );
		update_option( self::OPTION, $clean, true );
		$this->cache = null;
	}

	/**
	 * Store defaults on activation without overwriting existing settings.
	 *
	 * @return void
	 */
	public function ensure_defaults(): void {
		add_option( self::OPTION, $this->defaults(), '', true );
		$this->cache = null;
	}

	/**
	 * Clear the in-memory cache (after external option updates).
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$this->cache = null;
	}

	/**
	 * Field schema grouped into settings tabs.
	 *
	 * Field types: text, email, url, int, bool, textarea, html, select.
	 *
	 * @return array<string, array{title:string, description:string, fields:array<string, array<string, mixed>>}>
	 */
	public function get_sections(): array {
		$sections = array(
			'general'    => array(
				'title'       => __( 'General', 'ibg-client-outreach' ),
				'description' => __( 'Sender identity used for every campaign unless a campaign overrides it.', 'ibg-client-outreach' ),
				'fields'      => array(
					'business_name'    => array(
						'label'       => __( 'Business Name', 'ibg-client-outreach' ),
						'type'        => 'text',
						'description' => __( 'Shown in the email footer and used for sender identification.', 'ibg-client-outreach' ),
					),
					'business_website' => array(
						'label' => __( 'Website', 'ibg-client-outreach' ),
						'type'  => 'url',
					),
					'from_name'        => array(
						'label' => __( 'Default From Name', 'ibg-client-outreach' ),
						'type'  => 'text',
					),
					'from_email'       => array(
						'label'       => __( 'Default From Email', 'ibg-client-outreach' ),
						'type'        => 'email',
						'required'    => true,
						'description' => __( 'Use an address on a domain you control with SPF, DKIM and DMARC configured. Sending from a free mailbox domain (gmail.com, outlook.com) will be rejected or junked by most receivers.', 'ibg-client-outreach' ),
					),
					'reply_to'         => array(
						'label'       => __( 'Reply-To', 'ibg-client-outreach' ),
						'type'        => 'email',
						'description' => __( 'Optional. Leave blank to use the From Email.', 'ibg-client-outreach' ),
					),
				),
			),
			'email'      => array(
				'title'       => __( 'Email', 'ibg-client-outreach' ),
				'description' => __( 'Delivery provider and queue throttling.', 'ibg-client-outreach' ),
				'fields'      => array(
					'provider'    => array(
						'label'       => __( 'Sending Provider', 'ibg-client-outreach' ),
						'type'        => 'select',
						'options'     => array(),
						'description' => __( 'Additional providers (SMTP, SendGrid, Mailgun, Amazon SES, Brevo) can be registered by extensions.', 'ibg-client-outreach' ),
					),
					'batch_size'  => array(
						'label'       => __( 'Batch Size', 'ibg-client-outreach' ),
						'type'        => 'int',
						'min'         => 1,
						'max'         => 500,
						'description' => __( 'Emails processed per queue run. Keep this low on shared hosting (10–25).', 'ibg-client-outreach' ),
					),
					'batch_delay' => array(
						'label'       => __( 'Delay Between Batches (seconds)', 'ibg-client-outreach' ),
						'type'        => 'int',
						'min'         => 0,
						'max'         => 3600,
						'description' => __( 'Minimum time between queue runs. WP-Cron granularity is one minute, so values below 60 behave as 60.', 'ibg-client-outreach' ),
					),
					'max_retries' => array(
						'label'       => __( 'Maximum Retry Attempts', 'ibg-client-outreach' ),
						'type'        => 'int',
						'min'         => 0,
						'max'         => 10,
						'description' => __( 'How many times a failed send is retried before it is marked as failed permanently.', 'ibg-client-outreach' ),
					),
					'retry_delay' => array(
						'label'       => __( 'Retry Delay (minutes)', 'ibg-client-outreach' ),
						'type'        => 'int',
						'min'         => 1,
						'max'         => 1440,
						'description' => __( 'Wait time before a failed send is retried.', 'ibg-client-outreach' ),
					),
				),
			),
			'compliance' => array(
				'title'       => __( 'Compliance', 'ibg-client-outreach' ),
				'description' => __( 'Information that is included in every marketing email. You are responsible for ensuring you have a lawful basis to email each contact (for example GDPR/PECR in the EU and UK, CAN-SPAM in the US, CASL in Canada). This plugin provides the tools; it does not provide consent.', 'ibg-client-outreach' ),
				'fields'      => array(
					'business_address'   => array(
						'label'       => __( 'Business Address', 'ibg-client-outreach' ),
						'type'        => 'textarea',
						'required'    => true,
						'description' => __( 'A valid physical postal address. Required by CAN-SPAM and expected under most anti-spam laws.', 'ibg-client-outreach' ),
					),
					'consent_statement'  => array(
						'label'       => __( 'Why the recipient is receiving this email', 'ibg-client-outreach' ),
						'type'        => 'textarea',
						'description' => __( 'Explain the relationship or basis for contact. Available in the footer as {{consent_statement}}.', 'ibg-client-outreach' ),
					),
					'unsubscribe_text'   => array(
						'label'       => __( 'Unsubscribe Text', 'ibg-client-outreach' ),
						'type'        => 'text',
						'description' => __( 'Text shown before the unsubscribe link. Available as {{unsubscribe_text}}.', 'ibg-client-outreach' ),
					),
					'privacy_policy_url' => array(
						'label' => __( 'Privacy Policy URL', 'ibg-client-outreach' ),
						'type'  => 'url',
					),
					'email_footer'       => array(
						'label'       => __( 'Email Footer', 'ibg-client-outreach' ),
						'type'        => 'html',
						'rows'        => 10,
						'description' => __( 'Appended to every marketing email. Basic HTML allowed. Merge tags: {{business_name}}, {{business_website}}, {{business_address}}, {{consent_statement}}, {{unsubscribe_text}}, {{unsubscribe_link}}, {{unsubscribe_url}}, {{privacy_policy_url}}. An unsubscribe link is added automatically if the footer does not contain one.', 'ibg-client-outreach' ),
					),
				),
			),
			'privacy'    => array(
				'title'       => __( 'Privacy & Data', 'ibg-client-outreach' ),
				'description' => __( 'Tracking is off by default. Enable it only if your privacy policy discloses it and you have a lawful basis.', 'ibg-client-outreach' ),
				'fields'      => array(
					'track_opens'              => array(
						'label'       => __( 'Track Opens', 'ibg-client-outreach' ),
						'type'        => 'bool',
						'checkbox'    => __( 'Embed a tracking pixel in HTML emails', 'ibg-client-outreach' ),
						'description' => __( 'Open tracking works by loading a 1×1 image from your site when the email is displayed, which reveals that the recipient opened it and their approximate time and mail client. Many clients block or proxy images, so counts are unreliable. Under GDPR/ePrivacy this generally requires disclosure and a lawful basis.', 'ibg-client-outreach' ),
					),
					'track_clicks'             => array(
						'label'       => __( 'Track Clicks', 'ibg-client-outreach' ),
						'type'        => 'bool',
						'checkbox'    => __( 'Rewrite links in emails to record clicks', 'ibg-client-outreach' ),
						'description' => __( 'Links are routed through your site before redirecting. Disclose this in your privacy policy.', 'ibg-client-outreach' ),
					),
					'log_retention_days'       => array(
						'label'       => __( 'Email Log Retention (days)', 'ibg-client-outreach' ),
						'type'        => 'int',
						'min'         => 1,
						'max'         => 3650,
						'description' => __( 'Email logs older than this are deleted automatically.', 'ibg-client-outreach' ),
					),
					'queue_retention_days'     => array(
						'label'       => __( 'Queue Retention (days)', 'ibg-client-outreach' ),
						'type'        => 'int',
						'min'         => 1,
						'max'         => 3650,
						'description' => __( 'Completed queue rows older than this are deleted automatically.', 'ibg-client-outreach' ),
					),
					'delete_data_on_uninstall' => array(
						'label'       => __( 'Delete Data on Uninstall', 'ibg-client-outreach' ),
						'type'        => 'bool',
						'checkbox'    => __( 'Remove all contacts, campaigns, logs and settings when the plugin is deleted', 'ibg-client-outreach' ),
						'description' => __( 'This cannot be undone. Export your contacts first.', 'ibg-client-outreach' ),
					),
				),
			),
		);

		foreach ( $this->dynamic_options as $key => $callback ) {
			foreach ( $sections as &$section ) {
				if ( isset( $section['fields'][ $key ] ) ) {
					$section['fields'][ $key ]['options'] = (array) $callback();
				}
			}
			unset( $section );
		}

		/**
		 * Filter the settings schema. Extensions may add fields; every added
		 * field must declare a supported "type" so it is sanitised.
		 *
		 * @param array $sections Settings sections.
		 */
		return apply_filters( 'ibg_outreach_settings_sections', $sections );
	}

	/**
	 * Flat map of every field definition keyed by setting key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		$fields = array();
		foreach ( $this->get_sections() as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				$fields[ $key ] = $field;
			}
		}
		return $fields;
	}

	/**
	 * Sanitise submitted values and merge them over the current settings.
	 *
	 * When "_tab" is present only that tab's fields are processed, so saving
	 * one tab never clobbers another. Unknown keys are dropped.
	 *
	 * @param mixed $input Submitted values.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = $this->all();
		$sections = $this->get_sections();

		$tab = isset( $input['_tab'] ) ? sanitize_key( (string) $input['_tab'] ) : '';

		if ( '' !== $tab && isset( $sections[ $tab ] ) ) {
			$fields = $sections[ $tab ]['fields'];
		} else {
			$fields = $this->get_fields();
			// Programmatic update: only touch keys that were actually supplied.
			$fields = array_intersect_key( $fields, $input );
		}

		foreach ( $fields as $key => $field ) {
			$current[ $key ] = $this->sanitize_field( $key, $field, $input[ $key ] ?? null, $current[ $key ] ?? null );
		}

		$this->cache = null;

		return $current;
	}

	/**
	 * Sanitise a single value according to its field definition.
	 *
	 * @param string $key     Setting key.
	 * @param array  $field   Field definition.
	 * @param mixed  $value   Submitted value.
	 * @param mixed  $current Currently stored value (kept on validation failure).
	 * @return mixed
	 */
	private function sanitize_field( string $key, array $field, mixed $value, mixed $current ): mixed {
		$type = (string) ( $field['type'] ?? 'text' );

		switch ( $type ) {
			case 'bool':
				return ! empty( $value );

			case 'int':
				$int = (int) $value;
				if ( isset( $field['min'] ) ) {
					$int = max( (int) $field['min'], $int );
				}
				if ( isset( $field['max'] ) ) {
					$int = min( (int) $field['max'], $int );
				}
				return $int;

			case 'email':
				$email = sanitize_email( (string) $value );
				if ( '' !== $email && ! is_email( $email ) ) {
					$this->add_error( $key, sprintf(
						/* translators: %s: field label */
						__( '%s is not a valid email address. The previous value was kept.', 'ibg-client-outreach' ),
						$field['label'] ?? $key
					) );
					return $current;
				}
				if ( '' === $email && ! empty( $field['required'] ) ) {
					$this->add_error( $key, sprintf(
						/* translators: %s: field label */
						__( '%s is required.', 'ibg-client-outreach' ),
						$field['label'] ?? $key
					) );
					return $current;
				}
				return $email;

			case 'url':
				$url = esc_url_raw( trim( (string) $value ) );
				if ( '' !== $url && ! wp_http_validate_url( $url ) ) {
					$this->add_error( $key, sprintf(
						/* translators: %s: field label */
						__( '%s is not a valid URL. The previous value was kept.', 'ibg-client-outreach' ),
						$field['label'] ?? $key
					) );
					return $current;
				}
				return $url;

			case 'select':
				$value   = (string) $value;
				$options = (array) ( $field['options'] ?? array() );
				if ( array_key_exists( $value, $options ) ) {
					return $value;
				}
				return $current;

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'html':
				return wp_kses( (string) $value, $this->allowed_footer_html() );

			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * HTML permitted in the email footer.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public function allowed_footer_html(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			),
			'br'     => array(),
			'p'      => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'span'   => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
	}

	/**
	 * Register a validation error with the Settings API when available.
	 *
	 * @param string $key     Setting key.
	 * @param string $message Message.
	 * @return void
	 */
	private function add_error( string $key, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'ibg_' . $key, $message, 'error' );
		}
	}
}
