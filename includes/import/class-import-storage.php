<?php
/**
 * Storage for uploaded import files.
 *
 * Files live in uploads/ibg-outreach/imports/, are renamed to a random token,
 * protected from direct access (.htaccess + index.php), never included or
 * served, and removed once the import finishes or goes stale.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Class Import_Storage
 */
final class Import_Storage {

	/**
	 * Max age of an orphaned upload before cleanup.
	 */
	private const STALE_SECONDS = DAY_IN_SECONDS;

	/**
	 * Default maximum upload size in bytes (10 MB), filterable.
	 *
	 * @return int
	 */
	public static function max_size(): int {
		$max = (int) apply_filters( 'ibg_outreach_import_max_size', 10 * MB_IN_BYTES );
		return min( $max, wp_max_upload_size() );
	}

	/**
	 * Absolute path of the protected import directory (created on demand).
	 *
	 * @return string|\WP_Error
	 */
	public static function get_dir(): string|\WP_Error {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'upload_dir', (string) $upload['error'] );
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'ibg-outreach/imports/';

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'mkdir', __( 'The import directory could not be created.', 'ibg-client-outreach' ) );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents(
				$dir . '.htaccess',
				"# Deny direct access to import files.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
			);
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		}
		// phpcs:enable

		return $dir;
	}

	/**
	 * Validate and move an uploaded CSV into the protected directory.
	 *
	 * Validation is done manually rather than with wp_handle_upload() because
	 * finfo commonly reports CSV files as text/plain, which core's MIME check
	 * rejects on many hosts.
	 *
	 * @param array<string, mixed> $file  One entry of $_FILES.
	 * @param string               $token Session token used as the file name.
	 * @return string|\WP_Error Absolute destination path.
	 */
	public static function store_upload( array $file, string $token ): string|\WP_Error {
		$error_code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $error_code ) {
			$messages = array(
				UPLOAD_ERR_INI_SIZE   => __( 'The file exceeds the server upload limit.', 'ibg-client-outreach' ),
				UPLOAD_ERR_FORM_SIZE  => __( 'The file exceeds the allowed size.', 'ibg-client-outreach' ),
				UPLOAD_ERR_PARTIAL    => __( 'The file was only partially uploaded.', 'ibg-client-outreach' ),
				UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'ibg-client-outreach' ),
				UPLOAD_ERR_NO_TMP_DIR => __( 'The server has no temporary folder.', 'ibg-client-outreach' ),
				UPLOAD_ERR_CANT_WRITE => __( 'The server could not write the file.', 'ibg-client-outreach' ),
				UPLOAD_ERR_EXTENSION  => __( 'A server extension blocked the upload.', 'ibg-client-outreach' ),
			);
			return new \WP_Error( 'upload_error', $messages[ $error_code ] ?? __( 'Upload failed.', 'ibg-client-outreach' ) );
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new \WP_Error( 'not_uploaded', __( 'Invalid upload.', 'ibg-client-outreach' ) );
		}

		$size = (int) ( $file['size'] ?? 0 );
		if ( $size <= 0 ) {
			return new \WP_Error( 'empty', __( 'The uploaded file is empty.', 'ibg-client-outreach' ) );
		}
		if ( $size > self::max_size() ) {
			return new \WP_Error(
				'too_large',
				sprintf(
					/* translators: %s: formatted size */
					__( 'The file is larger than the %s limit.', 'ibg-client-outreach' ),
					size_format( self::max_size() )
				)
			);
		}

		$name      = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'txt' ), true ) ) {
			return new \WP_Error( 'bad_extension', __( 'Only .csv files can be imported.', 'ibg-client-outreach' ) );
		}

		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = $finfo ? (string) finfo_file( $finfo, $tmp ) : '';
			if ( $finfo ) {
				finfo_close( $finfo );
			}
			$allowed = array( 'text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/comma-separated-values', 'application/octet-stream' );
			if ( '' !== $mime && ! in_array( $mime, $allowed, true ) ) {
				return new \WP_Error( 'bad_mime', __( 'The file does not look like a CSV file.', 'ibg-client-outreach' ) );
			}
		}

		// Reject anything containing a PHP open tag or NUL bytes: it is not a spreadsheet export.
		$head = (string) file_get_contents( $tmp, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== strpos( $head, "\0" ) || preg_match( '/<\?php|<\?=/i', $head ) ) {
			return new \WP_Error( 'suspicious', __( 'The file does not look like a CSV file.', 'ibg-client-outreach' ) );
		}

		$dir = self::get_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		self::cleanup_stale( $dir );

		$destination = $dir . $token . '.csv';
		if ( ! move_uploaded_file( $tmp, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new \WP_Error( 'move_failed', __( 'The file could not be stored.', 'ibg-client-outreach' ) );
		}
		@chmod( $destination, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		return $destination;
	}

	/**
	 * Delete a stored import file (only inside our directory).
	 *
	 * @param string $path Absolute path.
	 * @return void
	 */
	public static function delete( string $path ): void {
		$dir = self::get_dir();
		if ( is_wp_error( $dir ) || '' === $path ) {
			return;
		}
		$real = realpath( $path );
		if ( $real && str_starts_with( $real, realpath( $dir ) ) && is_file( $real ) ) {
			wp_delete_file( $real );
		}
	}

	/**
	 * Remove orphaned uploads older than STALE_SECONDS.
	 *
	 * @param string $dir Import directory.
	 * @return void
	 */
	public static function cleanup_stale( string $dir ): void {
		$files = glob( $dir . '*.csv' );
		if ( ! is_array( $files ) ) {
			return;
		}
		$cutoff = time() - self::STALE_SECONDS;
		foreach ( $files as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
			}
		}
	}
}
