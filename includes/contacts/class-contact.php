<?php
/**
 * Contact entity.
 *
 * A plain data object mirroring one row of ibg_contacts. It contains no
 * persistence logic and no policy; see Contact_Repository and Contact_Service.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Contacts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Contact
 */
final class Contact {

	public const STATUS_LEAD     = 'lead';
	public const STATUS_CONTACT  = 'contact';
	public const STATUS_CUSTOMER = 'customer';
	public const STATUS_INACTIVE = 'inactive';

	public const MARKETING_SUBSCRIBED   = 'subscribed';
	public const MARKETING_PENDING      = 'pending';
	public const MARKETING_UNSUBSCRIBED = 'unsubscribed';
	public const MARKETING_DNC          = 'do_not_contact';

	public const EMAIL_VALID   = 'valid';
	public const EMAIL_INVALID = 'invalid';
	public const EMAIL_BOUNCED = 'bounced';

	/**
	 * Maximum lengths matching the schema; longer values are truncated on save.
	 *
	 * @var array<string, int>
	 */
	public const MAX_LENGTHS = array(
		'email'         => 190,
		'first_name'    => 100,
		'last_name'     => 100,
		'full_name'     => 200,
		'company'       => 200,
		'website'       => 255,
		'phone'         => 50,
		'country'       => 100,
		'industry'      => 100,
		'source'        => 100,
		'consent_basis' => 50,
	);

	public int $id                    = 0;
	public string $email              = '';
	public string $first_name         = '';
	public string $last_name          = '';
	public string $full_name          = '';
	public string $company            = '';
	public string $website            = '';
	public string $phone              = '';
	public string $country            = '';
	public string $industry           = '';
	public string $source             = '';
	public string $notes              = '';
	public string $contact_status     = self::STATUS_LEAD;
	public string $marketing_status   = self::MARKETING_PENDING;
	public string $email_status       = self::EMAIL_VALID;
	public string $consent_basis      = '';
	public ?string $consent_at        = null;
	public ?string $unsubscribed_at   = null;
	public ?string $last_contacted_at = null;
	public ?string $last_opened_at    = null;
	public ?string $last_clicked_at   = null;
	public string $created_at         = '';
	public string $updated_at         = '';

	/**
	 * Hydrate from a database row.
	 *
	 * @param array<string, mixed>|object $row Row.
	 * @return self
	 */
	public static function from_row( array|object $row ): self {
		$row     = (array) $row;
		$contact = new self();

		foreach ( get_object_vars( $contact ) as $property => $default ) {
			if ( ! array_key_exists( $property, $row ) ) {
				continue;
			}
			$value = $row[ $property ];

			if ( 'id' === $property ) {
				$contact->id = (int) $value;
			} elseif ( null === $default ) {
				// Nullable datetime columns.
				$contact->{$property} = ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) ? null : (string) $value;
			} else {
				$contact->{$property} = null === $value ? '' : (string) $value;
			}
		}

		return $contact;
	}

	/**
	 * Column => value map for wpdb insert/update (excludes id).
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		$row = get_object_vars( $this );
		unset( $row['id'] );
		return $row;
	}

	/**
	 * wpdb format specifiers in the same order as to_row().
	 *
	 * @return string[]
	 */
	public static function row_formats(): array {
		$formats = array();
		foreach ( array_keys( ( new self() )->to_row() ) as $column ) {
			$formats[] = '%s';
		}
		return $formats;
	}

	/**
	 * Name to show in lists: full name, else company, else email.
	 *
	 * @return string
	 */
	public function get_display_name(): string {
		if ( '' !== trim( $this->full_name ) ) {
			return $this->full_name;
		}
		$name = trim( $this->first_name . ' ' . $this->last_name );
		if ( '' !== $name ) {
			return $name;
		}
		return '' !== $this->company ? $this->company : $this->email;
	}

	/**
	 * Whether marketing email must never be sent to this contact.
	 *
	 * @return bool
	 */
	public function is_suppressed(): bool {
		return in_array( $this->marketing_status, self::suppressed_statuses(), true );
	}

	/**
	 * Whether the contact is eligible for campaigns (not suppressed, email valid).
	 * Campaigns may additionally restrict to "subscribed" only.
	 *
	 * @return bool
	 */
	public function is_mailable(): bool {
		return ! $this->is_suppressed() && self::EMAIL_VALID === $this->email_status;
	}

	/**
	 * Marketing statuses that block sending.
	 *
	 * @return string[]
	 */
	public static function suppressed_statuses(): array {
		return array( self::MARKETING_UNSUBSCRIBED, self::MARKETING_DNC );
	}

	/**
	 * Contact status labels.
	 *
	 * @return array<string, string>
	 */
	public static function contact_statuses(): array {
		return array(
			self::STATUS_LEAD     => __( 'Lead', 'ibg-client-outreach' ),
			self::STATUS_CONTACT  => __( 'Contact', 'ibg-client-outreach' ),
			self::STATUS_CUSTOMER => __( 'Customer', 'ibg-client-outreach' ),
			self::STATUS_INACTIVE => __( 'Inactive', 'ibg-client-outreach' ),
		);
	}

	/**
	 * Marketing status labels.
	 *
	 * @return array<string, string>
	 */
	public static function marketing_statuses(): array {
		return array(
			self::MARKETING_PENDING      => __( 'Pending', 'ibg-client-outreach' ),
			self::MARKETING_SUBSCRIBED   => __( 'Subscribed', 'ibg-client-outreach' ),
			self::MARKETING_UNSUBSCRIBED => __( 'Unsubscribed', 'ibg-client-outreach' ),
			self::MARKETING_DNC          => __( 'Do Not Contact', 'ibg-client-outreach' ),
		);
	}

	/**
	 * Email status labels.
	 *
	 * @return array<string, string>
	 */
	public static function email_statuses(): array {
		return array(
			self::EMAIL_VALID   => __( 'Valid', 'ibg-client-outreach' ),
			self::EMAIL_INVALID => __( 'Invalid', 'ibg-client-outreach' ),
			self::EMAIL_BOUNCED => __( 'Bounced', 'ibg-client-outreach' ),
		);
	}

	/**
	 * Consent / lawful-basis labels. Stored as the key.
	 *
	 * @return array<string, string>
	 */
	public static function consent_bases(): array {
		return array(
			''                    => __( '— Not recorded —', 'ibg-client-outreach' ),
			'opt_in'              => __( 'Explicit opt-in (form, written request)', 'ibg-client-outreach' ),
			'existing_client'     => __( 'Existing client', 'ibg-client-outreach' ),
			'inquiry'             => __( 'Inbound inquiry / requested information', 'ibg-client-outreach' ),
			'business_contact'    => __( 'Business contact (B2B relationship)', 'ibg-client-outreach' ),
			'legitimate_interest' => __( 'Legitimate interest (documented assessment)', 'ibg-client-outreach' ),
			'other'               => __( 'Other (see notes)', 'ibg-client-outreach' ),
		);
	}

	/**
	 * Label for a status value from one of the label maps.
	 *
	 * @param array<string, string> $map   Label map.
	 * @param string                $value Status value.
	 * @return string
	 */
	public static function label( array $map, string $value ): string {
		return $map[ $value ] ?? ucfirst( str_replace( '_', ' ', $value ) );
	}
}
