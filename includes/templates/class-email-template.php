<?php
/**
 * Email template entity.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Class Email_Template
 */
final class Email_Template {

	public int $id            = 0;
	public string $name       = '';
	public string $subject    = '';
	public string $body_html  = '';
	public string $body_text  = '';
	public bool $is_active    = true;
	public string $created_at = '';
	public string $updated_at = '';

	/**
	 * Number of campaigns referencing this template (set by the repository when requested).
	 *
	 * @var int|null
	 */
	public ?int $campaign_count = null;

	/**
	 * Hydrate from a row.
	 *
	 * @param array<string, mixed>|object $row Row.
	 * @return self
	 */
	public static function from_row( array|object $row ): self {
		$row = (array) $row;
		$t   = new self();

		$t->id         = (int) ( $row['id'] ?? 0 );
		$t->name       = (string) ( $row['name'] ?? '' );
		$t->subject    = (string) ( $row['subject'] ?? '' );
		$t->body_html  = (string) ( $row['body_html'] ?? '' );
		$t->body_text  = (string) ( $row['body_text'] ?? '' );
		$t->is_active  = ! empty( $row['is_active'] );
		$t->created_at = (string) ( $row['created_at'] ?? '' );
		$t->updated_at = (string) ( $row['updated_at'] ?? '' );

		if ( isset( $row['campaign_count'] ) ) {
			$t->campaign_count = (int) $row['campaign_count'];
		}

		return $t;
	}

	/**
	 * Column map (excludes id).
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'name'       => $this->name,
			'subject'    => $this->subject,
			'body_html'  => $this->body_html,
			'body_text'  => $this->body_text,
			'is_active'  => $this->is_active ? 1 : 0,
			'created_at' => $this->created_at,
			'updated_at' => $this->updated_at,
		);
	}

	/**
	 * wpdb formats matching to_row().
	 *
	 * @return string[]
	 */
	public static function row_formats(): array {
		return array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
	}
}
