<?php
/**
 * Database access helper and schema definition.
 *
 * Owns the list of plugin tables, their fully-prefixed names and the
 * dbDelta-compatible CREATE TABLE statements. Repositories in later phases
 * receive this object and never build table names themselves.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

defined( 'ABSPATH' ) || exit;

/**
 * Class Database
 */
final class Database {

	/**
	 * Short table names (without the "{$wpdb->prefix}ibg_" prefix).
	 *
	 * @var string[]
	 */
	public const TABLES = array(
		'contacts',
		'contact_meta',
		'lists',
		'contact_lists',
		'templates',
		'campaigns',
		'email_queue',
		'email_logs',
		'suppressions',
		'events',
	);

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Get the underlying wpdb instance.
	 *
	 * @return \wpdb
	 */
	public function wpdb(): \wpdb {
		return $this->wpdb;
	}

	/**
	 * Get the fully-prefixed name of a plugin table.
	 *
	 * Throws on unknown names so a table identifier can never be built from
	 * untrusted input.
	 *
	 * @param string $name Short table name, e.g. "contacts".
	 * @return string
	 *
	 * @throws \InvalidArgumentException When the table is not owned by the plugin.
	 */
	public function table( string $name ): string {
		if ( ! in_array( $name, self::TABLES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown IBG Outreach table "%s".', esc_html( $name ) ) );
		}
		return $this->wpdb->prefix . 'ibg_' . $name;
	}

	/**
	 * Current UTC timestamp in MySQL format.
	 *
	 * All plugin datetime columns are stored in UTC and converted for display.
	 *
	 * @return string
	 */
	public function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * Whether a plugin table exists.
	 *
	 * @param string $name Short table name.
	 * @return bool
	 */
	public function table_exists( string $name ): bool {
		$table = $this->table( $name );
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $table ) )
		);
		return $found === $table;
	}

	/**
	 * Short names of plugin tables that are missing from the database.
	 *
	 * @return string[]
	 */
	public function get_missing_tables(): array {
		$missing = array();
		foreach ( self::TABLES as $name ) {
			if ( ! $this->table_exists( $name ) ) {
				$missing[] = $name;
			}
		}
		return $missing;
	}

	/**
	 * dbDelta-compatible schema, keyed by short table name.
	 *
	 * dbDelta formatting rules are strict: two spaces after PRIMARY KEY, one
	 * field per line, lowercase "KEY", no backticks, no IF NOT EXISTS.
	 *
	 * @return array<string, string>
	 */
	public function get_schema(): array {
		$charset_collate = $this->wpdb->get_charset_collate();
		$schema          = array();

		$schema['contacts'] = "CREATE TABLE {$this->table( 'contacts' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(190) NOT NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  full_name varchar(200) NOT NULL DEFAULT '',
  company varchar(200) NOT NULL DEFAULT '',
  website varchar(255) NOT NULL DEFAULT '',
  phone varchar(50) NOT NULL DEFAULT '',
  country varchar(100) NOT NULL DEFAULT '',
  industry varchar(100) NOT NULL DEFAULT '',
  source varchar(100) NOT NULL DEFAULT '',
  notes longtext NULL,
  contact_status varchar(20) NOT NULL DEFAULT 'lead',
  marketing_status varchar(20) NOT NULL DEFAULT 'pending',
  email_status varchar(20) NOT NULL DEFAULT 'valid',
  consent_basis varchar(50) NOT NULL DEFAULT '',
  consent_at datetime NULL DEFAULT NULL,
  unsubscribed_at datetime NULL DEFAULT NULL,
  last_contacted_at datetime NULL DEFAULT NULL,
  last_opened_at datetime NULL DEFAULT NULL,
  last_clicked_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY contact_status (contact_status),
  KEY marketing_status (marketing_status),
  KEY email_status (email_status),
  KEY industry (industry),
  KEY country (country),
  KEY source (source),
  KEY company (company(100)),
  KEY last_name (last_name(50)),
  KEY created_at (created_at)
) {$charset_collate};";

		$schema['contact_meta'] = "CREATE TABLE {$this->table( 'contact_meta' )} (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  contact_id bigint(20) unsigned NOT NULL,
  meta_key varchar(255) NULL,
  meta_value longtext NULL,
  PRIMARY KEY  (meta_id),
  KEY contact_id (contact_id),
  KEY meta_key (meta_key(191))
) {$charset_collate};";

		$schema['lists'] = "CREATE TABLE {$this->table( 'lists' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  slug varchar(190) NOT NULL,
  description text NULL,
  type varchar(20) NOT NULL DEFAULT 'static',
  criteria longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY type (type)
) {$charset_collate};";

		$schema['contact_lists'] = "CREATE TABLE {$this->table( 'contact_lists' )} (
  contact_id bigint(20) unsigned NOT NULL,
  list_id bigint(20) unsigned NOT NULL,
  added_at datetime NOT NULL,
  PRIMARY KEY  (contact_id,list_id),
  KEY list_id (list_id)
) {$charset_collate};";

		$schema['templates'] = "CREATE TABLE {$this->table( 'templates' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  subject varchar(255) NOT NULL DEFAULT '',
  body_html longtext NULL,
  body_text longtext NULL,
  is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY is_active (is_active)
) {$charset_collate};";

		$schema['campaigns'] = "CREATE TABLE {$this->table( 'campaigns' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  subject varchar(255) NOT NULL DEFAULT '',
  from_name varchar(190) NOT NULL DEFAULT '',
  from_email varchar(190) NOT NULL DEFAULT '',
  reply_to varchar(190) NOT NULL DEFAULT '',
  template_id bigint(20) unsigned NULL DEFAULT NULL,
  list_id bigint(20) unsigned NULL DEFAULT NULL,
  segment longtext NULL,
  body_html longtext NULL,
  body_text longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  scheduled_at datetime NULL DEFAULT NULL,
  started_at datetime NULL DEFAULT NULL,
  completed_at datetime NULL DEFAULT NULL,
  total_recipients int(10) unsigned NOT NULL DEFAULT 0,
  total_excluded int(10) unsigned NOT NULL DEFAULT 0,
  total_sent int(10) unsigned NOT NULL DEFAULT 0,
  total_failed int(10) unsigned NOT NULL DEFAULT 0,
  total_skipped int(10) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY scheduled_at (scheduled_at),
  KEY template_id (template_id),
  KEY list_id (list_id)
) {$charset_collate};";

		$schema['email_queue'] = "CREATE TABLE {$this->table( 'email_queue' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  contact_id bigint(20) unsigned NOT NULL,
  email varchar(190) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  scheduled_at datetime NOT NULL,
  lock_token varchar(64) NULL DEFAULT NULL,
  locked_at datetime NULL DEFAULT NULL,
  last_attempt_at datetime NULL DEFAULT NULL,
  sent_at datetime NULL DEFAULT NULL,
  error_message text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY campaign_contact (campaign_id,contact_id),
  KEY contact_id (contact_id),
  KEY status_scheduled (status,scheduled_at),
  KEY lock_token (lock_token),
  KEY email (email)
) {$charset_collate};";

		$schema['email_logs'] = "CREATE TABLE {$this->table( 'email_logs' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
  queue_id bigint(20) unsigned NOT NULL DEFAULT 0,
  email varchar(190) NOT NULL,
  subject varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL,
  provider varchar(50) NOT NULL DEFAULT '',
  provider_message_id varchar(190) NULL DEFAULT NULL,
  error_message text NULL,
  retry_count tinyint(3) unsigned NOT NULL DEFAULT 0,
  sent_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY campaign_id (campaign_id),
  KEY contact_id (contact_id),
  KEY status (status),
  KEY email (email),
  KEY provider_message_id (provider_message_id),
  KEY created_at (created_at)
) {$charset_collate};";

		$schema['suppressions'] = "CREATE TABLE {$this->table( 'suppressions' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email_hash char(64) NOT NULL,
  email varchar(190) NULL DEFAULT NULL,
  contact_id bigint(20) unsigned NULL DEFAULT NULL,
  campaign_id bigint(20) unsigned NULL DEFAULT NULL,
  reason varchar(30) NOT NULL DEFAULT 'unsubscribed',
  source varchar(30) NOT NULL DEFAULT 'link',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email_hash (email_hash),
  KEY reason (reason),
  KEY created_at (created_at)
) {$charset_collate};";

		$schema['events'] = "CREATE TABLE {$this->table( 'events' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_type varchar(30) NOT NULL,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
  queue_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event_data longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY event_type (event_type),
  KEY campaign_id (campaign_id),
  KEY contact_id (contact_id),
  KEY created_at (created_at)
) {$charset_collate};";

		return $schema;
	}
}
