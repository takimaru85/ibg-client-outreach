<?php
/**
 * Contract every email delivery provider must implement.
 *
 * Providers are transport-only: merge tags, footers, unsubscribe links and
 * suppression checks are applied before a message reaches a provider.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email\Providers;

use IBG\Outreach\Email\Email_Message;
use IBG\Outreach\Email\Send_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Interface Email_Provider
 */
interface Email_Provider {

	/** Provider reports a message id that can be correlated with webhooks. */
	public const FEATURE_MESSAGE_ID = 'message_id';

	/** Provider can report delivery confirmations. */
	public const FEATURE_DELIVERED = 'delivered';

	/** Provider can report bounces. */
	public const FEATURE_BOUNCES = 'bounces';

	/** Provider can report spam complaints. */
	public const FEATURE_COMPLAINTS = 'complaints';

	/**
	 * Unique provider id (lowercase, underscores). Stored in settings and logs.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Short description shown on the settings screen.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Whether the provider has everything it needs to send.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Provider-specific settings fields, in the same schema format as
	 * Settings::get_sections() fields. Keys should be prefixed with the
	 * provider id, e.g. "smtp_host".
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_settings_fields(): array;

	/**
	 * Deliver a single message.
	 *
	 * Must never throw; wrap transport exceptions in a failed Send_Result.
	 *
	 * @param Email_Message $message Message to send.
	 * @return Send_Result
	 */
	public function send( Email_Message $message ): Send_Result;

	/**
	 * Whether the provider supports an optional feature (see FEATURE_* constants).
	 *
	 * @param string $feature Feature id.
	 * @return bool
	 */
	public function supports( string $feature ): bool;
}
