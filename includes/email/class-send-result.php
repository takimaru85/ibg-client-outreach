<?php
/**
 * Result of a provider send attempt.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Class Send_Result
 */
final class Send_Result {

	/**
	 * Constructor.
	 *
	 * @param bool   $success    Whether the provider accepted the message.
	 * @param string $message_id Provider message id, when available (used for bounce/delivery correlation).
	 * @param string $error      Error message on failure.
	 * @param bool   $retryable  Whether a failure is transient and worth retrying.
	 */
	private function __construct(
		private readonly bool $success,
		private readonly string $message_id = '',
		private readonly string $error = '',
		private readonly bool $retryable = true
	) {}

	/**
	 * Successful send.
	 *
	 * @param string $message_id Provider message id.
	 * @return self
	 */
	public static function success( string $message_id = '' ): self {
		return new self( true, $message_id );
	}

	/**
	 * Failed send.
	 *
	 * @param string $error     Error message.
	 * @param bool   $retryable Whether the queue should retry.
	 * @return self
	 */
	public static function failure( string $error, bool $retryable = true ): self {
		return new self( false, '', $error, $retryable );
	}

	/** @return bool */
	public function is_success(): bool {
		return $this->success;
	}

	/** @return string */
	public function get_message_id(): string {
		return $this->message_id;
	}

	/** @return string */
	public function get_error(): string {
		return $this->error;
	}

	/** @return bool */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}
