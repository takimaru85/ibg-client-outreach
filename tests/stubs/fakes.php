<?php
/**
 * In-memory test doubles for the database-backed collaborators.
 *
 * They are declared before the autoloader so services under test receive
 * these instead of the real repositories. Only the methods the pure classes
 * call are implemented.
 *
 * @package IBG\Outreach
 */

declare( strict_types=1 );

// phpcs:disable Squiz.Commenting, Generic.Files.OneObjectStructurePerFile, WordPress.Files.FileName

namespace IBG\Outreach {

	final class Activator {
		public const OPTION_SECRET = 'ibg_outreach_secret_key';
	}

	final class Database {
		public function now(): string {
			return gmdate( 'Y-m-d H:i:s' );
		}
		public function table( string $name ): string {
			return 'wp_ibg_' . $name;
		}
	}

	final class Settings {
		public array $values = array();
		public function get( string $key, mixed $fallback = null ): mixed {
			return $this->values[ $key ] ?? $fallback;
		}
		public function get_secret( string $key ): string {
			return Secrets::decrypt( (string) $this->get( $key, '' ) );
		}
		public static function sanitize_color( string $value ): string {
			return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', trim( $value ) ) ? strtolower( trim( $value ) ) : '';
		}
	}
}

namespace IBG\Outreach\Contacts {

	final class Contact_Repository {
		public array $rows  = array();
		private int $next   = 1;
		public array $bulk  = array();

		public function find( int $id ): ?Contact {
			return isset( $this->rows[ $id ] ) ? clone $this->rows[ $id ] : null;
		}
		public function find_by_email( string $email ): ?Contact {
			foreach ( $this->rows as $c ) {
				if ( $c->email === $email ) {
					return clone $c;
				}
			}
			return null;
		}
		public function find_many( array $ids ): array {
			return array_intersect_key( $this->rows, array_flip( array_map( 'intval', $ids ) ) );
		}
		public function find_ids_by_emails( array $emails ): array {
			$map = array();
			foreach ( $this->rows as $c ) {
				if ( in_array( $c->email, $emails, true ) ) {
					$map[ $c->email ] = $c->id;
				}
			}
			return $map;
		}
		public function insert( Contact $c ): int {
			$c->id                  = $this->next++;
			$c->created_at          = gmdate( 'Y-m-d H:i:s' );
			$c->updated_at          = $c->created_at;
			$this->rows[ $c->id ] = clone $c;
			return $c->id;
		}
		public function update( Contact $c ): bool {
			$this->rows[ $c->id ] = clone $c;
			return true;
		}
		public function update_many( array $ids, array $fields ): int {
			$this->bulk[] = array( $ids, $fields );
			return count( $ids );
		}
		public function delete_many( array $ids ): int {
			$n = 0;
			foreach ( $ids as $id ) {
				if ( isset( $this->rows[ $id ] ) ) {
					unset( $this->rows[ $id ] );
					++$n;
				}
			}
			return $n;
		}
	}
}

namespace IBG\Outreach\Unsubscribe {

	final class Suppression_Repository {
		public const REASON_UNSUBSCRIBED = 'unsubscribed';
		public const REASON_DNC          = 'do_not_contact';
		public const REASON_BOUNCED      = 'bounced';
		public const REASON_COMPLAINT    = 'complaint';
		public const SOURCE_LINK         = 'link';
		public const SOURCE_ADMIN        = 'admin';
		public const SOURCE_IMPORT       = 'import';
		public const SOURCE_SYSTEM       = 'system';

		public array $rows = array();

		public function find( string $email ): ?object {
			return isset( $this->rows[ $email ] ) ? (object) $this->rows[ $email ] : null;
		}
		public function is_suppressed( string $email ): bool {
			return isset( $this->rows[ $email ] );
		}
		public function find_suppressed( array $emails ): array {
			$out = array();
			foreach ( $emails as $e ) {
				if ( isset( $this->rows[ $e ] ) ) {
					$out[ $e ] = $this->rows[ $e ]['reason'];
				}
			}
			return $out;
		}
		public function add( string $email, string $reason, string $source, ?int $contact_id = null, ?int $campaign_id = null ): bool {
			if ( isset( $this->rows[ $email ] ) && self::REASON_DNC === $this->rows[ $email ]['reason'] && self::REASON_DNC !== $reason ) {
				return true;
			}
			$this->rows[ $email ] = array(
				'id'          => count( $this->rows ) + 1,
				'email'       => $email,
				'reason'      => $reason,
				'source'      => $source,
				'contact_id'  => $contact_id,
				'campaign_id' => $campaign_id,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			);
			return true;
		}
		public function remove( string $email ): bool {
			unset( $this->rows[ $email ] );
			return true;
		}
	}
}

namespace IBG\Outreach\Events {

	final class Event_Repository {
		public const CONTACT_CREATED          = 'contact.created';
		public const CONTACT_UPDATED          = 'contact.updated';
		public const CONTACT_DELETED          = 'contact.deleted';
		public const CONTACT_STATUS_CHANGED   = 'contact.status_changed';
		public const MARKETING_STATUS_CHANGED = 'contact.marketing_status_changed';
		public const SUPPRESSION_PRESERVED    = 'contact.suppression_preserved';
		public const CONTACT_RESUBSCRIBED     = 'contact.resubscribed';

		public array $log = array();

		public function log( string $type, int $contact_id = 0, array $data = array(), int $campaign_id = 0, int $queue_id = 0 ): int {
			$this->log[] = compact( 'type', 'contact_id', 'data', 'campaign_id', 'queue_id' );
			return count( $this->log );
		}
		public function types(): array {
			return array_column( $this->log, 'type' );
		}
		public function delete_for_contacts( array $ids ): int {
			return 0;
		}
	}
}

namespace IBG\Outreach\Queue {

	final class Queue_Repository {
		public array $items = array();
		public function find( int $id ): ?object {
			return $this->items[ $id ] ?? null;
		}
	}
}
