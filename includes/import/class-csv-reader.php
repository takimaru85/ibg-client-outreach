<?php
/**
 * Streaming CSV reader.
 *
 * Never loads the whole file into memory; rows are read in windows so large
 * files can be analysed and imported in batches. Handles a UTF-8 BOM,
 * delimiter detection and Latin-1 fallback for non-UTF-8 input.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Class CSV_Reader
 */
final class CSV_Reader {

	/**
	 * Constructor.
	 *
	 * @param string $path      Absolute file path.
	 * @param string $delimiter Field delimiter.
	 */
	public function __construct(
		private readonly string $path,
		private readonly string $delimiter = ','
	) {}

	/**
	 * Guess the delimiter from the first line.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	public static function detect_delimiter( string $path ): string {
		$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return ',';
		}
		$line = (string) fgets( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$best  = ',';
		$count = -1;
		foreach ( array( ',', ';', "\t", '|' ) as $candidate ) {
			$n = substr_count( $line, $candidate );
			if ( $n > $count ) {
				$count = $n;
				$best  = $candidate;
			}
		}
		return $best;
	}

	/**
	 * Header row, cleaned. Empty header cells become "Column N".
	 *
	 * @return string[]
	 */
	public function get_headers(): array {
		$handle = $this->open();
		if ( ! $handle ) {
			return array();
		}
		$row = $this->read_record( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! is_array( $row ) || array( null ) === $row ) {
			return array();
		}

		// A UTF-8 BOM can only precede the very first cell.
		$row[0]  = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
		$row     = $this->normalize( $row );
		$headers = array();
		foreach ( $row as $i => $value ) {
			$value         = trim( (string) $value );
			$headers[ $i ] = '' !== $value ? $value : sprintf( 'Column %d', $i + 1 );
		}
		return $headers;
	}

	/**
	 * Read a window of data rows.
	 *
	 * Row indexes are 0-based positions among non-blank data rows and are
	 * stable between count(), read() and analysis.
	 *
	 * @param int $offset First data row index.
	 * @param int $limit  Max rows.
	 * @return array<int, string[]> index => values.
	 */
	public function read( int $offset, int $limit ): array {
		$handle = $this->open();
		if ( ! $handle ) {
			return array();
		}

		$this->read_record( $handle ); // Header.

		$rows  = array();
		$index = 0;

		while ( true ) {
			$row = $this->read_record( $handle );
			if ( false === $row ) {
				break;
			}
			if ( array( null ) === $row ) {
				continue; // Blank line.
			}
			if ( $index >= $offset ) {
				$rows[ $index ] = $this->normalize( $row );
				if ( count( $rows ) >= $limit ) {
					break;
				}
			}
			++$index;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}

	/**
	 * Number of non-blank data rows.
	 *
	 * @return int
	 */
	public function count(): int {
		$handle = $this->open();
		if ( ! $handle ) {
			return 0;
		}

		$this->read_record( $handle ); // Header.

		$count = 0;
		while ( true ) {
			$row = $this->read_record( $handle );
			if ( false === $row ) {
				break;
			}
			if ( array( null ) !== $row ) {
				++$count;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $count;
	}

	/**
	 * Open the file for reading.
	 *
	 * @return resource|false
	 */
	private function open() {
		if ( ! is_readable( $this->path ) ) {
			return false;
		}
		return @fopen( $this->path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	}

	/**
	 * Read one CSV record (handles quoted multi-line fields).
	 *
	 * @param resource $handle File handle.
	 * @return array|false
	 */
	private function read_record( $handle ): array|false {
		// Empty escape string disables PHP's legacy backslash escaping (RFC 4180 behaviour).
		$row = fgetcsv( $handle, 0, $this->delimiter, '"', '' );
		return is_array( $row ) ? $row : false;
	}

	/**
	 * Enforce UTF-8 and trim every cell.
	 *
	 * @param array $row Raw row.
	 * @return string[]
	 */
	private function normalize( array $row ): array {
		$out = array();
		foreach ( $row as $i => $value ) {
			$value = (string) $value;
			if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
				$value = mb_convert_encoding( $value, 'UTF-8', 'ISO-8859-1' );
			}
			$out[ $i ] = trim( $value );
		}
		return $out;
	}
}
