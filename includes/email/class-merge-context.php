<?php
/**
 * Data available to merge tags for one email.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email;

use IBG\Outreach\Contacts\Contact;

defined( 'ABSPATH' ) || exit;

/**
 * Class Merge_Context
 */
final class Merge_Context {

	/**
	 * Constructor.
	 *
	 * @param Contact|null         $contact     Recipient contact (null for synthetic previews).
	 * @param int                  $campaign_id Campaign id.
	 * @param int                  $queue_id    Queue row id.
	 * @param bool                 $is_test     Whether this is a test/preview render.
	 * @param array<string, mixed> $extra       Extra values for custom tags.
	 */
	public function __construct(
		public readonly ?Contact $contact = null,
		public readonly int $campaign_id = 0,
		public readonly int $queue_id = 0,
		public readonly bool $is_test = false,
		public readonly array $extra = array()
	) {}

	/**
	 * A synthetic contact for previews when no real contact is chosen.
	 *
	 * @return self
	 */
	public static function sample(): self {
		$contact                   = new Contact();
		$contact->first_name       = 'Jane';
		$contact->last_name        = 'Doe';
		$contact->full_name        = 'Jane Doe';
		$contact->company          = 'Acme Dental';
		$contact->email            = 'jane@example.com';
		$contact->website          = 'https://example.com';
		$contact->phone            = '+1 555 0100';
		$contact->country          = 'United States';
		$contact->industry         = 'Dental';
		$contact->marketing_status = Contact::MARKETING_PENDING;

		return new self( $contact, 0, 0, true );
	}

	/**
	 * Whether the context refers to a stored contact.
	 *
	 * @return bool
	 */
	public function has_real_contact(): bool {
		return $this->contact instanceof Contact && $this->contact->id > 0;
	}
}
