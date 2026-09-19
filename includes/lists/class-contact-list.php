<?php
/**
 * List / segment entity.
 *
 * A "static" list has explicit memberships in ibg_contact_lists.
 * A "segment" stores filter criteria and is evaluated live.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Lists;

defined( 'ABSPATH' ) || exit;

/**
 * Class Contact_List
 */
final class Contact_List {

	public const TYPE_STATIC  = 'static';
	public const TYPE_SEGMENT = 'segment';

	public int $id             = 0;
	public string $name        = '';
	public string $slug        = '';
	public string $description = '';
	public string $type        = self::TYPE_STATIC;

	/**
	 * Segment criteria (see Segment_Criteria).
	 *
	 * @var array<string, mixed>
	 */
	public array $criteria = array();

	public string $created_at = '';
	public string $updated_at = '';

	/**
	 * Member count when loaded via List_Repository::all(); not a column.
	 *
	 * @var int|null
	 */
	public ?int $member_count = null;

	/**
	 * Hydrate from a row.
	 *
	 * @param array<string, mixed>|object $row Row.
	 * @return self
	 */
	public static function from_row( array|object $row ): self {
		$row  = (array) $row;
		$list = new self();

		$list->id          = (int) ( $row['id'] ?? 0 );
		$list->name        = (string) ( $row['name'] ?? '' );
		$list->slug        = (string) ( $row['slug'] ?? '' );
		$list->description = (string) ( $row['description'] ?? '' );
		$list->type        = self::TYPE_SEGMENT === ( $row['type'] ?? '' ) ? self::TYPE_SEGMENT : self::TYPE_STATIC;
		$list->created_at  = (string) ( $row['created_at'] ?? '' );
		$list->updated_at  = (string) ( $row['updated_at'] ?? '' );

		$criteria       = isset( $row['criteria'] ) && is_string( $row['criteria'] ) && '' !== $row['criteria'] ? json_decode( $row['criteria'], true ) : array();
		$list->criteria = is_array( $criteria ) ? $criteria : array();

		if ( isset( $row['member_count'] ) ) {
			$list->member_count = (int) $row['member_count'];
		}

		return $list;
	}

	/**
	 * Column map for wpdb (excludes id and computed fields).
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'name'        => $this->name,
			'slug'        => $this->slug,
			'description' => $this->description,
			'type'        => $this->type,
			'criteria'    => $this->is_segment() && ! empty( $this->criteria ) ? wp_json_encode( $this->criteria ) : null,
			'created_at'  => $this->created_at,
			'updated_at'  => $this->updated_at,
		);
	}

	/**
	 * wpdb formats matching to_row().
	 *
	 * @return string[]
	 */
	public static function row_formats(): array {
		return array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
	}

	/**
	 * Whether this is a dynamic segment.
	 *
	 * @return bool
	 */
	public function is_segment(): bool {
		return self::TYPE_SEGMENT === $this->type;
	}

	/**
	 * Type labels.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			self::TYPE_STATIC  => __( 'List (fixed members)', 'ibg-client-outreach' ),
			self::TYPE_SEGMENT => __( 'Segment (dynamic filter)', 'ibg-client-outreach' ),
		);
	}
}
