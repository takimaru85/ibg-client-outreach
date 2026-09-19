<?php
/**
 * List service: validation, audience resolution and counts.
 *
 * get_audience_args() is the one place that turns a list or segment into
 * Contact_Repository query args; campaigns, the contacts table and the
 * lists screen all go through it.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Lists;

use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Events\Event_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Class List_Service
 */
final class List_Service {

	/**
	 * Constructor.
	 *
	 * @param List_Repository    $lists    Lists.
	 * @param Contact_Repository $contacts Contacts.
	 * @param Event_Repository   $events   Events.
	 */
	public function __construct(
		private readonly List_Repository $lists,
		private readonly Contact_Repository $contacts,
		private readonly Event_Repository $events
	) {}

	/**
	 * Create or update a list/segment.
	 *
	 * @param array<string, mixed> $data Raw input: name, description, type, criteria.
	 * @param int                  $id   Existing id, or 0 to create.
	 * @return Contact_List|\WP_Error
	 */
	public function save( array $data, int $id = 0 ): Contact_List|\WP_Error {
		$existing = $id > 0 ? $this->lists->find( $id ) : null;
		if ( $id > 0 && ! $existing ) {
			return new \WP_Error( 'not_found', __( 'List not found.', 'ibg-client-outreach' ) );
		}

		$name = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 190 );
		if ( '' === $name ) {
			return new \WP_Error( 'name_required', __( 'A name is required.', 'ibg-client-outreach' ) );
		}

		$list              = $existing ?? new Contact_List();
		$list->name        = $name;
		$list->description = sanitize_textarea_field( (string) ( $data['description'] ?? '' ) );
		$list->slug        = $this->lists->unique_slug( $name, $list->id );

		// Type is fixed after creation: converting would orphan memberships or criteria.
		if ( ! $existing ) {
			$list->type = Contact_List::TYPE_SEGMENT === ( $data['type'] ?? '' ) ? Contact_List::TYPE_SEGMENT : Contact_List::TYPE_STATIC;
		}

		if ( $list->is_segment() ) {
			$criteria = Segment_Criteria::sanitize( is_array( $data['criteria'] ?? null ) ? $data['criteria'] : array() );
			if ( ! empty( $criteria['list_id'] ) ) {
				$target = $this->lists->find( (int) $criteria['list_id'] );
				if ( ! $target || $target->is_segment() || $target->id === $list->id ) {
					return new \WP_Error( 'invalid_list', __( '"Member of list" must refer to a fixed list.', 'ibg-client-outreach' ) );
				}
			}
			$list->criteria = $criteria;
		} else {
			$list->criteria = array();
		}

		if ( $existing ) {
			if ( ! $this->lists->update( $list ) ) {
				return new \WP_Error( 'db_error', __( 'The list could not be saved.', 'ibg-client-outreach' ) );
			}
		} elseif ( ! $this->lists->insert( $list ) ) {
			return new \WP_Error( 'db_error', __( 'The list could not be saved.', 'ibg-client-outreach' ) );
		}

		return $list;
	}

	/**
	 * Delete lists.
	 *
	 * @param int[] $ids List ids.
	 * @return int
	 */
	public function delete( array $ids ): int {
		return $this->lists->delete_many( $ids );
	}

	/**
	 * Contact_Repository query args that select this list's audience.
	 *
	 * @param Contact_List $list List or segment.
	 * @return array<string, mixed>
	 */
	public function get_audience_args( Contact_List $list ): array {
		if ( $list->is_segment() ) {
			return Segment_Criteria::to_query_args( $list->criteria );
		}
		return array( 'list_id' => $list->id );
	}

	/**
	 * Number of contacts in a list or matching a segment.
	 *
	 * @param Contact_List $list List.
	 * @return int
	 */
	public function count( Contact_List $list ): int {
		if ( ! $list->is_segment() && null !== $list->member_count ) {
			return $list->member_count;
		}
		return $this->contacts->count( $this->get_audience_args( $list ) );
	}

	/**
	 * Replace a contact's memberships and log the change.
	 *
	 * @param int   $contact_id Contact id.
	 * @param int[] $list_ids   Static list ids.
	 * @return void
	 */
	public function set_contact_lists( int $contact_id, array $list_ids ): void {
		// Only static lists can have members.
		$static   = $this->lists->all( Contact_List::TYPE_STATIC );
		$list_ids = array_values( array_intersect( array_map( 'intval', $list_ids ), array_keys( $static ) ) );

		$diff = $this->lists->set_contact_lists( $contact_id, $list_ids );

		if ( ! empty( $diff['added'] ) || ! empty( $diff['removed'] ) ) {
			$this->events->log( 'contact.lists_changed', $contact_id, $diff );
		}
	}

	/**
	 * Add contacts to a static list.
	 *
	 * @param int   $list_id     List id.
	 * @param int[] $contact_ids Contact ids.
	 * @return int|\WP_Error Memberships added.
	 */
	public function add_contacts( int $list_id, array $contact_ids ): int|\WP_Error {
		$list = $this->lists->find( $list_id );
		if ( ! $list || $list->is_segment() ) {
			return new \WP_Error( 'invalid_list', __( 'Contacts can only be added to fixed lists.', 'ibg-client-outreach' ) );
		}
		return $this->lists->add_contacts( $list_id, $contact_ids );
	}

	/**
	 * Remove contacts from a static list.
	 *
	 * @param int   $list_id     List id.
	 * @param int[] $contact_ids Contact ids.
	 * @return int|\WP_Error Memberships removed.
	 */
	public function remove_contacts( int $list_id, array $contact_ids ): int|\WP_Error {
		$list = $this->lists->find( $list_id );
		if ( ! $list || $list->is_segment() ) {
			return new \WP_Error( 'invalid_list', __( 'Invalid list.', 'ibg-client-outreach' ) );
		}
		return $this->lists->remove_contacts( $list_id, $contact_ids );
	}
}
