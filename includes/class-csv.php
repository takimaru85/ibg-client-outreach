<?php
/**
 * CSV output helper.
 *
 * Neutralises spreadsheet formula injection: a cell starting with = + - @
 * (or a tab/CR) is prefixed with an apostrophe so Excel/Sheets treat it as
 * text instead of executing it when the export is opened.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

defined( 'ABSPATH' ) || exit;

/**
 * Class Csv
 */
final class Csv {

	/**
	 * Output stream.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Send download headers and open php://output. Must be called before any output.
	 *
	 * @param string $filename File name (without path).
	 */
	public function __construct( string $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$this->handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		// UTF-8 BOM so Excel opens accented characters correctly.
		fwrite( $this->handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	/**
	 * Write one row.
	 *
	 * @param array<int, mixed> $row Cells.
	 * @return void
	 */
	public function row( array $row ): void {
		fputcsv( $this->handle, array_map( array( __CLASS__, 'cell' ), $row ) );
	}

	/**
	 * Close the stream and stop.
	 *
	 * @return never
	 */
	public function finish(): never {
		fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Make a cell safe for spreadsheets.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function cell( mixed $value ): string {
		$value = (string) $value;
		if ( '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
