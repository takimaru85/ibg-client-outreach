<?php
/**
 * Suppression policy tests — the plugin's central compliance guarantee.
 *
 * @package IBG\Outreach
 */

declare( strict_types=1 );

namespace IBG\Outreach\Tests\Unit;

use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Contacts\Contact_Service;
use IBG\Outreach\Database;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Unsubscribe\Suppression_Repository;
use PHPUnit\Framework\TestCase;

final class ContactServicePolicyTest extends TestCase {

	private Contact_Repository $contacts;
	private Suppression_Repository $suppressions;
	private Event_Repository $events;
	private Contact_Service $service;

	protected function setUp(): void {
		$this->contacts     = new Contact_Repository();
		$this->suppressions = new Suppression_Repository();
		$this->events       = new Event_Repository();
		$this->service      = new Contact_Service( $this->contacts, $this->suppressions, $this->events, new Database() );
	}

	private function create( array $data = array(), array $options = array() ): Contact {
		$result = $this->service->create( array_merge( array( 'email' => 'bob@acme.test' ), $data ), $options );
		self::assertInstanceOf( Contact::class, $result );
		return $result;
	}

	public function test_create_normalises_email_and_defaults_to_pending(): void {
		$c = $this->create( array( 'email' => ' Bob@ACME.test ' ) );
		self::assertSame( 'bob@acme.test', $c->email );
		self::assertSame( Contact::MARKETING_PENDING, $c->marketing_status );
		self::assertSame( Contact::STATUS_LEAD, $c->contact_status );
	}

	public function test_duplicate_email_is_rejected(): void {
		$this->create();
		$result = $this->service->create( array( 'email' => 'BOB@acme.test' ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'email_duplicate', $result->get_error_code() );
	}

	public function test_full_name_is_derived_and_re_derived(): void {
		$c = $this->create( array( 'first_name' => 'Bob', 'last_name' => 'Ray' ) );
		self::assertSame( 'Bob Ray', $c->full_name );
		$c = $this->service->update( $c->id, array( 'last_name' => 'Rae' ) );
		self::assertSame( 'Bob Rae', $c->full_name );
	}

	public function test_public_unsubscribe_writes_suppression_with_campaign(): void {
		$c = $this->create();
		$r = $this->service->update( $c->id, array( 'marketing_status' => 'unsubscribed' ), array( 'source' => Contact_Service::SOURCE_PUBLIC, 'campaign_id' => 7 ) );
		self::assertSame( Contact::MARKETING_UNSUBSCRIBED, $r->marketing_status );
		self::assertNotNull( $r->unsubscribed_at );
		self::assertSame( 'link', $this->suppressions->rows['bob@acme.test']['source'] );
		self::assertSame( 7, $this->suppressions->rows['bob@acme.test']['campaign_id'] );
		self::assertContains( Event_Repository::MARKETING_STATUS_CHANGED, $this->events->types() );
	}

	public function test_import_cannot_lift_unsubscribe(): void {
		$c = $this->create();
		$this->service->update( $c->id, array( 'marketing_status' => 'unsubscribed' ) );
		$r = $this->service->update( $c->id, array( 'marketing_status' => 'subscribed' ), array( 'source' => Contact_Service::SOURCE_IMPORT ) );
		self::assertSame( Contact::MARKETING_UNSUBSCRIBED, $r->marketing_status );
		self::assertNotEmpty( $this->service->take_notices() );
		self::assertContains( Event_Repository::SUPPRESSION_PRESERVED, $this->events->types() );
	}

	public function test_api_cannot_lift_unsubscribe_even_with_confirmation_flag(): void {
		$c = $this->create();
		$this->service->update( $c->id, array( 'marketing_status' => 'unsubscribed' ) );
		$r = $this->service->update( $c->id, array( 'marketing_status' => 'pending' ), array( 'source' => Contact_Service::SOURCE_API, 'confirm_resubscribe' => true ) );
		self::assertSame( Contact::MARKETING_UNSUBSCRIBED, $r->marketing_status );
	}

	public function test_public_resubscribe_lifts_plain_unsubscribe_only(): void {
		$c = $this->create();
		$this->service->update( $c->id, array( 'marketing_status' => 'unsubscribed' ), array( 'source' => Contact_Service::SOURCE_PUBLIC ) );
		$r = $this->service->update( $c->id, array( 'marketing_status' => 'pending' ), array( 'source' => Contact_Service::SOURCE_PUBLIC, 'confirm_resubscribe' => true ) );
		self::assertSame( Contact::MARKETING_PENDING, $r->marketing_status );
		self::assertArrayNotHasKey( 'bob@acme.test', $this->suppressions->rows );
		self::assertNull( $r->unsubscribed_at );
	}

	public function test_public_resubscribe_cannot_lift_do_not_contact(): void {
		$c = $this->create();
		$this->service->update( $c->id, array( 'marketing_status' => 'do_not_contact' ) );
		$r = $this->service->update( $c->id, array( 'marketing_status' => 'pending' ), array( 'source' => Contact_Service::SOURCE_PUBLIC, 'confirm_resubscribe' => true ) );
		self::assertSame( Contact::MARKETING_DNC, $r->marketing_status );
	}

	public function test_public_unsubscribe_cannot_downgrade_do_not_contact(): void {
		$c = $this->create();
		$this->service->update( $c->id, array( 'marketing_status' => 'do_not_contact' ) );
		$r = $this->service->update( $c->id, array( 'marketing_status' => 'unsubscribed' ), array( 'source' => Contact_Service::SOURCE_PUBLIC ) );
		self::assertSame( Contact::MARKETING_DNC, $r->marketing_status );
		self::assertSame( 'do_not_contact', $this->suppressions->rows['bob@acme.test']['reason'] );
	}

	public function test_admin_with_confirmation_lifts_do_not_contact(): void {
		$c = $this->create();
		$this->service->update( $c->id, array( 'marketing_status' => 'do_not_contact' ) );
		$r = $this->service->update( $c->id, array( 'marketing_status' => 'pending' ), array( 'source' => Contact_Service::SOURCE_ADMIN, 'confirm_resubscribe' => true ) );
		self::assertSame( Contact::MARKETING_PENDING, $r->marketing_status );
		self::assertArrayNotHasKey( 'bob@acme.test', $this->suppressions->rows );
		self::assertContains( Event_Repository::CONTACT_RESUBSCRIBED, $this->events->types() );
	}

	public function test_suppression_survives_deletion_and_blocks_reimport(): void {
		$c = $this->create();
		$this->service->update( $c->id, array( 'marketing_status' => 'unsubscribed' ), array( 'source' => Contact_Service::SOURCE_PUBLIC ) );
		self::assertSame( 1, $this->service->delete( array( $c->id ) ) );

		$again = $this->service->create( array( 'email' => 'bob@acme.test', 'marketing_status' => 'subscribed' ), array( 'source' => Contact_Service::SOURCE_IMPORT ) );
		self::assertInstanceOf( Contact::class, $again );
		self::assertSame( Contact::MARKETING_UNSUBSCRIBED, $again->marketing_status );
		self::assertSame( $again->id, $this->suppressions->rows['bob@acme.test']['contact_id'] );
	}

	public function test_subscribed_stamps_consent_at(): void {
		$c = $this->create( array( 'marketing_status' => 'subscribed' ) );
		self::assertNotNull( $c->consent_at );
	}

	public function test_website_gets_scheme_and_invalid_status_rejected(): void {
		$c = $this->create( array( 'website' => 'acme.test/page' ) );
		self::assertSame( 'https://acme.test/page', $c->website );

		$r = $this->service->create( array( 'email' => 'x@acme.test', 'contact_status' => 'bogus' ) );
		self::assertInstanceOf( \WP_Error::class, $r );
	}
}
