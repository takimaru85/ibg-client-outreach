# Developer reference

## Architecture

```
ibg-client-outreach.php     bootstrap, requirements gate, activation hooks
includes/
  class-plugin.php          service container (lazy factories) – ibg_outreach()->get( 'id' )
  class-database.php        table names + dbDelta schema      class-installer.php   versioned migrations
  class-settings.php        option schema + sanitisation      class-capabilities.php
  contacts/   lists/   templates/   campaigns/   queue/   email/   import/
  unsubscribe/   analytics/   privacy/   rest/   events/
admin/  pages (controllers) · tables (WP_List_Table) · views (templates, escaping only)
public/ unsubscribe page template
```

Every module follows *Repository* (SQL only) → *Service* (rules) → *Page/Controller* (HTTP). Views never query.

### Container services

| id | Class | Purpose |
|---|---|---|
| `database`, `installer`, `settings`, `capabilities` | core | |
| `contacts`, `contact_service` | `Contacts\*` | all contact writes go through the service |
| `lists`, `list_service` | `Lists\*` | `get_audience_args()` resolves lists/segments |
| `templates`, `template_service` | `Templates\*` | |
| `campaigns`, `campaign_service`, `audience` | `Campaigns\*` | state machine, pre-flight, recipient resolution |
| `queue`, `queue_filler`, `queue_worker`, `logs`, `cron` | `Queue\*` | |
| `merge_tags`, `composer`, `providers`, `delivery_events`, `webhooks` | `Email\*` | |
| `suppressions`, `unsubscribe_token`, `unsubscribe_endpoint` | `Unsubscribe\*` | |
| `tracking`, `stats`, `signer` | analytics | |
| `importer`, `events`, `erasure`, `privacy`, `rest_api` | | |

```php
$service = ibg_outreach()->get( 'contact_service' );
$contact = $service->create( array( 'email' => 'a@b.test', 'company' => 'Acme' ), array( 'source' => 'api' ) );
```

## Actions

| Hook | Args | When |
|---|---|---|
| `ibg_outreach_loaded` | `Plugin` | Container ready (register services/providers here) |
| `ibg_outreach_register_email_providers` | `Provider_Registry` | Add providers |
| `ibg_outreach_register_merge_tags` | `Merge_Tags` | Add merge tags |
| `ibg_outreach_register_admin_pages` | `Menu, Plugin, Notices` | Add admin pages |
| `ibg_outreach_contact_created` / `_updated` | `Contact[, Contact $before], array $options` | |
| `ibg_outreach_contacts_before_delete` | `Contact[]` | |
| `ibg_outreach_contact_unsubscribed` | `Contact, int $campaign_id, string $source` | Public endpoint |
| `ibg_outreach_import_completed` | `array $summary, Import_Session` | |
| `ibg_outreach_campaign_started` / `_paused` / `_resumed` / `_cancelled` / `_completed` | `Campaign[, string $trigger]` | |
| `ibg_outreach_campaign_deleted` | `int $id` | |
| `ibg_outreach_email_sent` | `Queue_Item, Contact, Campaign` | Provider accepted the message |
| `ibg_outreach_queue_processed` | `array $stats` | After each worker run |
| `ibg_outreach_delivery_event` | `Delivery_Event, Contact, int $campaign_id, string $provider` | Webhook applied |
| `ibg_outreach_tracking_event` | `string $type, int $contact, int $campaign, int $queue, array $data` | Open/click |
| `ibg_outreach_personal_data_erased` | `string $email, array $result` | |
| `ibg_outreach_smtp_before_send` | `PHPMailer, Email_Message` | DKIM signing, debugging |
| `ibg_outreach_cleanup_done` | | Daily retention finished |

## Filters

| Filter | Purpose |
|---|---|
| `ibg_outreach_default_roles` | Roles granted capabilities on activation |
| `ibg_outreach_settings_sections` | Add settings fields (must declare a `type`) |
| `ibg_outreach_import_max_size`, `ibg_outreach_import_batch_size`, `ibg_outreach_import_header_aliases` | Importer |
| `ibg_outreach_template_allowed_html` | KSES allow-list for templates |
| `ibg_outreach_email_wrapper` | HTML document wrapper (`{{body}}`, `{{footer}}`) |
| `ibg_outreach_composed_email` | Final subject/html/text/headers before sending |
| `ibg_outreach_campaign_send_args` | Narrow a campaign's audience |
| `ibg_outreach_campaign_preflight` | Add pre-flight checks |
| `ibg_outreach_queue_skip_reason` | Veto a queued send (return a reason string) |
| `ibg_outreach_queue_time_budget` | Seconds per worker run (default 20) |
| `ibg_outreach_unsubscribe_template`, `ibg_outreach_unsubscribe_page_data` | Public page |
| `ibg_outreach_rest_controllers` | Add REST controllers |

## Adding a merge tag

```php
add_action( 'ibg_outreach_register_merge_tags', function ( $tags ) {
	$tags->register(
		'city',
		'City',
		fn( $ctx ) => $ctx->contact ? (string) get_contact_meta( $ctx->contact->id, 'city' ) : '',
		\IBG\Outreach\Email\Merge_Tags::TYPE_TEXT,
		'contact'
	);
} );
```

Types: `text` (escaped in HTML), `url`, `multiline` (nl2br), `html` (resolver must escape). Resolvers receive `( Merge_Context $ctx, string $format )`.

## Adding a provider

Implement `Email\Providers\Email_Provider`; optionally `Webhook_Provider` for bounce/complaint feedback.

```php
final class Acme_Provider implements Email_Provider, Webhook_Provider {
	public function __construct( private Settings $settings ) {}
	public function get_id(): string { return 'acme'; }
	public function get_name(): string { return 'Acme API'; }
	public function get_description(): string { return '…'; }
	public function is_configured(): bool { return '' !== $this->settings->get_secret( 'acme_api_key' ); }
	public function supports( string $f ): bool { return in_array( $f, [ self::FEATURE_MESSAGE_ID, self::FEATURE_BOUNCES, self::FEATURE_COMPLAINTS ], true ); }

	public function get_settings_fields(): array {
		return [ 'acme_api_key' => [ 'label' => 'API key', 'type' => 'password', 'default' => '' ] ];
	}

	public function send( Email_Message $m ): Send_Result {
		$res = wp_remote_post( 'https://api.acme.test/send', [ 'headers' => [ 'Authorization' => 'Bearer ' . $this->settings->get_secret( 'acme_api_key' ) ], 'body' => wp_json_encode( [ /* … */ ] ) ] );
		if ( is_wp_error( $res ) ) { return Send_Result::failure( $res->get_error_message(), true ); }
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code >= 400 ) { return Send_Result::failure( wp_remote_retrieve_body( $res ), $code >= 500 ); }
		return Send_Result::success( json_decode( wp_remote_retrieve_body( $res ) )->id ?? '' );
	}

	public function verify_webhook( \WP_REST_Request $r ): bool { /* check signature header */ }
	public function parse_webhook( \WP_REST_Request $r ): array { /* return Delivery_Event[] */ }
}

add_action( 'ibg_outreach_register_email_providers', fn( $registry ) => $registry->register( new Acme_Provider( ibg_outreach()->get( 'settings' ) ) ) );
```

Point the provider's webhook at `https://example.com/wp-json/ibg/v1/webhooks/acme`. `Delivery_Event` types: `delivered`, `bounced`, `soft_bounced`, `complained`, `opened`, `clicked`.

## REST API

Namespace `ibg/v1`. Authenticate with a logged-in cookie + `X-WP-Nonce`, or an Application Password.

| Route | Methods | Capability |
|---|---|---|
| `/contacts` | GET (search, contact_status, marketing_status, email_status, industry, country, source, consent_basis, list_id, date_from, date_to, page, per_page, orderby, order), POST | view / manage_contacts |
| `/contacts/{id}` | GET, PUT/PATCH (partial), DELETE | |
| `/templates`, `/templates/{id}` | GET, POST, PUT, DELETE | view / manage_campaigns |
| `/campaigns`, `/campaigns/{id}` | GET (single includes `audience`, `preflight`, `engagement`), POST, PUT, DELETE | view / manage_campaigns |
| `/campaigns/{id}/action` | POST `action=schedule\|unschedule\|start\|pause\|resume\|cancel` (+`scheduled_at`) | send_campaigns |
| `/stats` | GET (`days`) | view |
| `/webhooks/{provider}` | POST | provider signature |

Collections return `X-WP-Total` / `X-WP-TotalPages`. Errors are `{code, message, data:{status}}`; service errors map to 400/404/409.

## Database

Tables: `contacts`, `contact_meta`, `lists`, `contact_lists`, `templates`, `campaigns`, `email_queue`, `email_logs`, `suppressions`, `events` (all `{$wpdb->prefix}ibg_`). All datetimes are UTC. Schema lives in `Database::get_schema()`; bump `IBG_OUTREACH_DB_VERSION` and (for data migrations) add to `Installer::MIGRATIONS`.

## Tests

```
composer install
composer test        # unit suite, no WordPress needed
WP_TESTS_DIR=/path/to/wordpress-tests-lib composer test   # integration mode
composer phpcs
```
