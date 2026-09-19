<?php
/**
 * Campaign entity.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Campaigns;

defined( 'ABSPATH' ) || exit;

/**
 * Class Campaign
 */
final class Campaign {

	public const STATUS_DRAFT      = 'draft';
	public const STATUS_SCHEDULED  = 'scheduled';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_PAUSED     = 'paused';
	public const STATUS_COMPLETED  = 'completed';
	public const STATUS_CANCELLED  = 'cancelled';

	/** Send only to contacts whose marketing status is Subscribed. */
	public const SCOPE_SUBSCRIBED = 'subscribed';

	/** Send to Subscribed and Pending contacts (site owner asserts a lawful basis). */
	public const SCOPE_SUBSCRIBED_PENDING = 'subscribed_pending';

	public int $id              = 0;
	public string $name         = '';
	public string $subject      = '';
	public string $from_name    = '';
	public string $from_email   = '';
	public string $reply_to     = '';
	public ?int $template_id    = null;
	public ?int $list_id        = null;
	public string $scope        = self::SCOPE_SUBSCRIBED;

	/**
	 * Ad-hoc criteria layered on top of the list/segment (reserved for the REST/API layer).
	 *
	 * @var array<string, mixed>
	 */
	public array $criteria = array();

	public string $body_html     = '';
	public string $body_text     = '';
	public string $status        = self::STATUS_DRAFT;
	public ?string $scheduled_at = null;
	public ?string $started_at   = null;
	public ?string $completed_at = null;
	public int $total_recipients = 0;
	public int $total_excluded   = 0;
	public int $total_sent       = 0;
	public int $total_failed     = 0;
	public int $total_skipped    = 0;
	public int $created_by       = 0;
	public string $created_at    = '';
	public string $updated_at    = '';

	/**
	 * Hydrate from a row.
	 *
	 * @param array<string, mixed>|object $row Row.
	 * @return self
	 */
	public static function from_row( array|object $row ): self {
		$row = (array) $row;
		$c   = new self();

		$c->id          = (int) ( $row['id'] ?? 0 );
		$c->name        = (string) ( $row['name'] ?? '' );
		$c->subject     = (string) ( $row['subject'] ?? '' );
		$c->from_name   = (string) ( $row['from_name'] ?? '' );
		$c->from_email  = (string) ( $row['from_email'] ?? '' );
		$c->reply_to    = (string) ( $row['reply_to'] ?? '' );
		$c->template_id = isset( $row['template_id'] ) && '' !== (string) $row['template_id'] ? (int) $row['template_id'] : null;
		$c->list_id     = isset( $row['list_id'] ) && '' !== (string) $row['list_id'] ? (int) $row['list_id'] : null;
		$c->body_html   = (string) ( $row['body_html'] ?? '' );
		$c->body_text   = (string) ( $row['body_text'] ?? '' );
		$c->status      = array_key_exists( (string) ( $row['status'] ?? '' ), self::statuses() ) ? (string) $row['status'] : self::STATUS_DRAFT;

		foreach ( array( 'scheduled_at', 'started_at', 'completed_at' ) as $key ) {
			$value     = $row[ $key ] ?? null;
			$c->{$key} = ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) ? null : (string) $value;
		}
		foreach ( array( 'total_recipients', 'total_excluded', 'total_sent', 'total_failed', 'total_skipped', 'created_by' ) as $key ) {
			$c->{$key} = (int) ( $row[ $key ] ?? 0 );
		}
		$c->created_at = (string) ( $row['created_at'] ?? '' );
		$c->updated_at = (string) ( $row['updated_at'] ?? '' );

		$segment = isset( $row['segment'] ) && is_string( $row['segment'] ) && '' !== $row['segment'] ? json_decode( $row['segment'], true ) : array();
		if ( is_array( $segment ) ) {
			$c->scope    = self::SCOPE_SUBSCRIBED_PENDING === ( $segment['scope'] ?? '' ) ? self::SCOPE_SUBSCRIBED_PENDING : self::SCOPE_SUBSCRIBED;
			$c->criteria = is_array( $segment['criteria'] ?? null ) ? $segment['criteria'] : array();
		}

		return $c;
	}

	/**
	 * Column map (excludes id).
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'name'             => $this->name,
			'subject'          => $this->subject,
			'from_name'        => $this->from_name,
			'from_email'       => $this->from_email,
			'reply_to'         => $this->reply_to,
			'template_id'      => $this->template_id,
			'list_id'          => $this->list_id,
			'segment'          => wp_json_encode(
				array(
					'scope'    => $this->scope,
					'criteria' => $this->criteria,
				)
			),
			'body_html'        => $this->body_html,
			'body_text'        => $this->body_text,
			'status'           => $this->status,
			'scheduled_at'     => $this->scheduled_at,
			'started_at'       => $this->started_at,
			'completed_at'     => $this->completed_at,
			'total_recipients' => $this->total_recipients,
			'total_excluded'   => $this->total_excluded,
			'total_sent'       => $this->total_sent,
			'total_failed'     => $this->total_failed,
			'total_skipped'    => $this->total_skipped,
			'created_by'       => $this->created_by,
			'created_at'       => $this->created_at,
			'updated_at'       => $this->updated_at,
		);
	}

	/**
	 * wpdb formats matching to_row().
	 *
	 * @return string[]
	 */
	public static function row_formats(): array {
		return array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' );
	}

	/**
	 * Whether the campaign can still be edited.
	 *
	 * @return bool
	 */
	public function is_editable(): bool {
		return in_array( $this->status, array( self::STATUS_DRAFT, self::STATUS_SCHEDULED ), true );
	}

	/**
	 * Whether sending has begun (a body snapshot exists).
	 *
	 * @return bool
	 */
	public function has_started(): bool {
		return null !== $this->started_at;
	}

	/**
	 * Whether the status is terminal.
	 *
	 * @return bool
	 */
	public function is_finished(): bool {
		return in_array( $this->status, array( self::STATUS_COMPLETED, self::STATUS_CANCELLED ), true );
	}

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_DRAFT      => __( 'Draft', 'ibg-client-outreach' ),
			self::STATUS_SCHEDULED  => __( 'Scheduled', 'ibg-client-outreach' ),
			self::STATUS_PROCESSING => __( 'Processing', 'ibg-client-outreach' ),
			self::STATUS_PAUSED     => __( 'Paused', 'ibg-client-outreach' ),
			self::STATUS_COMPLETED  => __( 'Completed', 'ibg-client-outreach' ),
			self::STATUS_CANCELLED  => __( 'Cancelled', 'ibg-client-outreach' ),
		);
	}

	/**
	 * Scope labels.
	 *
	 * @return array<string, string>
	 */
	public static function scopes(): array {
		return array(
			self::SCOPE_SUBSCRIBED         => __( 'Subscribed contacts only (recommended)', 'ibg-client-outreach' ),
			self::SCOPE_SUBSCRIBED_PENDING => __( 'Subscribed and Pending contacts', 'ibg-client-outreach' ),
		);
	}
}
