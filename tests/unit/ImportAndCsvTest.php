<?php
/**
 * CSV reader, mapping guesser, segment criteria and CSV output safety.
 *
 * @package IBG\Outreach
 */

declare( strict_types=1 );

namespace IBG\Outreach\Tests\Unit;

use IBG\Outreach\Csv;
use IBG\Outreach\Import\Contact_Importer;
use IBG\Outreach\Import\CSV_Reader;
use IBG\Outreach\Lists\Segment_Criteria;
use PHPUnit\Framework\TestCase;

final class ImportAndCsvTest extends TestCase {

	private string $file;

	protected function setUp(): void {
		$this->file = tempnam( sys_get_temp_dir(), 'ibg' );
		file_put_contents(
			$this->file,
			"\xEF\xBB\xBFFirst Name;Last Name;Company Name;E-Mail;Web Site;Notes\n"
			. "Ian;Olden;IB Golden;IAN@Example.com;ibgolden.com;\"multi\nline note\"\n"
			. "\n"
			. ";;Acme;bad-email;;\n"
			. "Jane;Doe;Acme;jane@acme.test;;\"quoted; semicolon\"\n"
		);
	}

	protected function tearDown(): void {
		unlink( $this->file );
	}

	public function test_delimiter_detection(): void {
		self::assertSame( ';', CSV_Reader::detect_delimiter( $this->file ) );
	}

	public function test_headers_strip_bom(): void {
		$reader = new CSV_Reader( $this->file, ';' );
		self::assertSame( array( 'First Name', 'Last Name', 'Company Name', 'E-Mail', 'Web Site', 'Notes' ), $reader->get_headers() );
	}

	public function test_rows_skip_blank_lines_and_keep_stable_indexes(): void {
		$reader = new CSV_Reader( $this->file, ';' );
		self::assertSame( 3, $reader->count() );

		$rows = $reader->read( 0, 10 );
		self::assertSame( array( 0, 1, 2 ), array_keys( $rows ) );
		self::assertSame( "multi\nline note", $rows[0][5] );
		self::assertSame( 'quoted; semicolon', $rows[2][5] );

		$window = $reader->read( 1, 1 );
		self::assertSame( array( 1 ), array_keys( $window ) );
		self::assertSame( 'bad-email', $window[1][3] );
	}

	public function test_mapping_guesser(): void {
		$reader = new CSV_Reader( $this->file, ';' );
		self::assertSame(
			array( 'first_name', 'last_name', 'company', 'email', 'website', 'notes' ),
			array_values( Contact_Importer::guess_mapping( $reader->get_headers() ) )
		);
	}

	public function test_mapping_uses_each_field_once(): void {
		$mapping = Contact_Importer::guess_mapping( array( 'Email', 'E-mail', 'Name', 'Contact' ) );
		self::assertSame( array( 'email', '', 'full_name', '' ), array_values( $mapping ) );
	}

	public function test_segment_criteria_sanitise(): void {
		$clean = Segment_Criteria::sanitize(
			array(
				'industry'      => array( 'Dental', '', 'Dental', '<b>Real Estate</b>' ),
				'list_id'       => '5',
				'date_from'     => '2026-01-01',
				'date_to'       => 'nope',
				'search'        => ' acme ',
				'mailable_only' => '1',
				'unknown'       => 'x',
			)
		);
		self::assertSame(
			array(
				'industry'      => array( 'Dental', 'Real Estate' ),
				'list_id'       => 5,
				'date_from'     => '2026-01-01',
				'search'        => 'acme',
				'mailable_only' => true,
			),
			$clean
		);
	}

	public function test_csv_cell_neutralises_formulas(): void {
		self::assertSame( "'=HYPERLINK(\"x\")", Csv::cell( '=HYPERLINK("x")' ) );
		self::assertSame( "'+1", Csv::cell( '+1' ) );
		self::assertSame( "'-2", Csv::cell( '-2' ) );
		self::assertSame( "'@cmd", Csv::cell( '@cmd' ) );
		self::assertSame( 'plain', Csv::cell( 'plain' ) );
		self::assertSame( '', Csv::cell( '' ) );
	}
}
