<?php
/**
 * Import session: state of one import wizard run, persisted in a transient.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Class Import_Session
 */
final class Import_Session {

	public const STEP_MAP     = 'map';
	public const STEP_PREVIEW = 'preview';

	private const TRANSIENT_PREFIX = 'ibg_outreach_import_';
	private const TTL              = 2 * HOUR_IN_SECONDS;

	public string $token         = '';
	public int $user_id          = 0;
	public string $step          = self::STEP_MAP;
	public string $file_path     = '';
	public string $original_name = '';
	public string $delimiter     = ',';

	/**
	 * CSV headers by column index.
	 *
	 * @var string[]
	 */
	public array $headers = array();

	/**
	 * First few data rows for the mapping preview.
	 *
	 * @var array<int, string[]>
	 */
	public array $samples = array();

	/**
	 * Column index => contact field ('' = ignore).
	 *
	 * @var array<int, string>
	 */
	public array $mapping = array();

	/**
	 * Import-wide options (statuses, defaults, duplicate strategy).
	 *
	 * @var array<string, mixed>
	 */
	public array $options = array();

	/**
	 * Result of Contact_Importer::analyze().
	 *
	 * @var array<string, mixed>
	 */
	public array $analysis = array();

	/**
	 * Batch progress.
	 *
	 * @var array<string, mixed>
	 */
	public array $progress = array();

	/**
	 * Start a new session for a user.
	 *
	 * @param int $user_id User id.
	 * @return self
	 */
	public static function create( int $user_id ): self {
		$session          = new self();
		$session->token   = bin2hex( random_bytes( 16 ) );
		$session->user_id = $user_id;
		$session->progress = self::empty_progress();
		return $session;
	}

	/**
	 * Load a session by token.
	 *
	 * @param string $token Token.
	 * @return self|null
	 */
	public static function load( string $token ): ?self {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$data = get_transient( self::TRANSIENT_PREFIX . $token );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$session = new self();
		foreach ( get_object_vars( $session ) as $property => $default ) {
			if ( array_key_exists( $property, $data ) ) {
				$session->{$property} = is_array( $default ) ? (array) $data[ $property ] : ( is_int( $default ) ? (int) $data[ $property ] : (string) $data[ $property ] );
			}
		}
		$session->token = $token;

		return $session;
	}

	/**
	 * Persist.
	 *
	 * @return void
	 */
	public function save(): void {
		set_transient( self::TRANSIENT_PREFIX . $this->token, get_object_vars( $this ), self::TTL );
	}

	/**
	 * Delete the session and its file.
	 *
	 * @return void
	 */
	public function destroy(): void {
		Import_Storage::delete( $this->file_path );
		delete_transient( self::TRANSIENT_PREFIX . $this->token );
	}

	/**
	 * Whether the session belongs to the current user.
	 *
	 * @return bool
	 */
	public function is_owned_by_current_user(): bool {
		return $this->user_id > 0 && get_current_user_id() === $this->user_id;
	}

	/**
	 * Whether the import has finished.
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return ! empty( $this->progress['done'] );
	}

	/**
	 * Initial progress counters.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_progress(): array {
		return array(
			'offset'           => 0,
			'processed'        => 0,
			'created'          => 0,
			'updated'          => 0,
			'skipped_invalid'  => 0,
			'skipped_existing' => 0,
			'preserved'        => 0,
			'failed'           => 0,
			'listed'           => 0,
			'errors'           => array(),
			'done'             => false,
			'started_at'       => '',
			'finished_at'      => '',
		);
	}
}
