<?php
/**
 * Normalised delivery event reported by a provider.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Class Delivery_Event
 */
final class Delivery_Event {

	public const DELIVERED    = 'delivered';
	public const BOUNCED      = 'bounced';      // Hard / permanent.
	public const SOFT_BOUNCED = 'soft_bounced'; // Temporary (mailbox full, greylisting).
	public const COMPLAINED   = 'complained';   // Marked as spam.
	public const OPENED       = 'opened';
	public const CLICKED      = 'clicked';

	/**
	 * Constructor.
	 *
	 * @param string               $type       One of the class constants.
	 * @param string               $email      Recipient address.
	 * @param string               $message_id Provider message id, if known.
	 * @param int                  $timestamp  Unix timestamp of the event.
	 * @param array<string, mixed> $data       Extra data (reason, URL, …).
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $email,
		public readonly string $message_id = '',
		public readonly int $timestamp = 0,
		public readonly array $data = array()
	) {}

	/**
	 * Known event types.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array( self::DELIVERED, self::BOUNCED, self::SOFT_BOUNCED, self::COMPLAINED, self::OPENED, self::CLICKED );
	}
}
