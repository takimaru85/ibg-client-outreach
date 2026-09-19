<?php
/**
 * Queue row entity.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Class Queue_Item
 */
final class Queue_Item {

	public const STATUS_PENDING = 'pending';
	public const STATUS_SENDING = 'sending';
	public const STATUS_SENT    = 'sent';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	public int $id                  = 0;
	public int $campaign_id         = 0;
	public int $contact_id          = 0;
	public string $email            = '';
	public string $status           = self::STATUS_PENDING;
	public int $attempts            = 0;
	public string $scheduled_at     = '';
	public ?string $lock_token      = null;
	public ?string $locked_at       = null;
	public ?string $last_attempt_at = null;
	public ?string $sent_at         = null;
	public string $error_message    = '';
	public string $created_at       = '';

	/**
	 * Hydrate from a row.
	 *
	 * @param array<string, mixed>|object $row Row.
	 * @return self
	 */
	public static function from_row( array|object $row ): self {
		$row  = (array) $row;
		$item = new self();

		$item->id           = (int) ( $row['id'] ?? 0 );
		$item->campaign_id  = (int) ( $row['campaign_id'] ?? 0 );
		$item->contact_id   = (int) ( $row['contact_id'] ?? 0 );
		$item->email        = (string) ( $row['email'] ?? '' );
		$item->status       = (string) ( $row['status'] ?? self::STATUS_PENDING );
		$item->attempts     = (int) ( $row['attempts'] ?? 0 );
		$item->scheduled_at = (string) ( $row['scheduled_at'] ?? '' );
		$item->created_at   = (string) ( $row['created_at'] ?? '' );
		$item->error_message = (string) ( $row['error_message'] ?? '' );

		foreach ( array( 'lock_token', 'locked_at', 'last_attempt_at', 'sent_at' ) as $key ) {
			$value        = $row[ $key ] ?? null;
			$item->{$key} = ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) ? null : (string) $value;
		}

		return $item;
	}

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_PENDING => __( 'Pending', 'ibg-client-outreach' ),
			self::STATUS_SENDING => __( 'Sending', 'ibg-client-outreach' ),
			self::STATUS_SENT    => __( 'Sent', 'ibg-client-outreach' ),
			self::STATUS_FAILED  => __( 'Failed', 'ibg-client-outreach' ),
			self::STATUS_SKIPPED => __( 'Skipped', 'ibg-client-outreach' ),
		);
	}
}
