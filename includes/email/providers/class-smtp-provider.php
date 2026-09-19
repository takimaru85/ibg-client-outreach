<?php
/**
 * SMTP provider: sends through PHPMailer with the plugin's own SMTP settings,
 * independent of wp_mail() and of any other mail plugin.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email\Providers;

use IBG\Outreach\Email\Email_Message;
use IBG\Outreach\Email\Send_Result;
use IBG\Outreach\Secrets;
use IBG\Outreach\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class SMTP_Provider
 */
final class SMTP_Provider implements Email_Provider {

	public const ID = 'smtp';

	/** Optional wp-config.php override for the password. */
	public const PASSWORD_CONSTANT = 'IBG_OUTREACH_SMTP_PASSWORD';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/** @inheritDoc */
	public function get_id(): string {
		return self::ID;
	}

	/** @inheritDoc */
	public function get_name(): string {
		return __( 'SMTP server', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_description(): string {
		return __( 'Sends directly through an SMTP server (your email host, or the SMTP endpoint of a transactional service such as Brevo, Mailgun, SendGrid or Amazon SES). Independent of wp_mail() and other mail plugins.', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function is_configured(): bool {
		if ( '' === trim( (string) $this->settings->get( 'smtp_host', '' ) ) ) {
			return false;
		}
		if ( $this->settings->get( 'smtp_auth', true ) ) {
			return '' !== (string) $this->settings->get( 'smtp_username', '' ) && '' !== $this->password();
		}
		return true;
	}

	/** @inheritDoc */
	public function get_settings_fields(): array {
		$password_help = defined( self::PASSWORD_CONSTANT )
			? __( 'Defined by the IBG_OUTREACH_SMTP_PASSWORD constant in wp-config.php; the stored value is ignored.', 'ibg-client-outreach' )
			: ( Secrets::is_available()
				? __( 'Stored encrypted. Leave blank to keep the saved password. For best security define IBG_OUTREACH_SMTP_PASSWORD in wp-config.php instead.', 'ibg-client-outreach' )
				: __( 'Warning: OpenSSL is unavailable, so the password would be stored in plain text. Define IBG_OUTREACH_SMTP_PASSWORD in wp-config.php instead.', 'ibg-client-outreach' ) );

		return array(
			'smtp_host'       => array(
				'label'       => __( 'SMTP Host', 'ibg-client-outreach' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'e.g. smtp-relay.brevo.com, smtp.mailgun.org, email-smtp.eu-west-1.amazonaws.com', 'ibg-client-outreach' ),
			),
			'smtp_port'       => array(
				'label'   => __( 'SMTP Port', 'ibg-client-outreach' ),
				'type'    => 'int',
				'min'     => 1,
				'max'     => 65535,
				'default' => 587,
			),
			'smtp_encryption' => array(
				'label'       => __( 'Encryption', 'ibg-client-outreach' ),
				'type'        => 'select',
				'options'     => array(
					'tls'  => __( 'STARTTLS (usually port 587)', 'ibg-client-outreach' ),
					'ssl'  => __( 'SSL/TLS (usually port 465)', 'ibg-client-outreach' ),
					'none' => __( 'None (not recommended)', 'ibg-client-outreach' ),
				),
				'default'     => 'tls',
			),
			'smtp_auth'       => array(
				'label'    => __( 'Authentication', 'ibg-client-outreach' ),
				'type'     => 'bool',
				'checkbox' => __( 'The server requires a username and password', 'ibg-client-outreach' ),
				'default'  => true,
			),
			'smtp_username'   => array(
				'label'   => __( 'Username', 'ibg-client-outreach' ),
				'type'    => 'text',
				'default' => '',
			),
			'smtp_password'   => array(
				'label'       => __( 'Password', 'ibg-client-outreach' ),
				'type'        => 'password',
				'default'     => '',
				'description' => $password_help,
			),
		);
	}

	/** @inheritDoc */
	public function supports( string $feature ): bool {
		return self::FEATURE_MESSAGE_ID === $feature;
	}

	/** @inheritDoc */
	public function send( Email_Message $message ): Send_Result {
		if ( ! $this->is_configured() ) {
			return Send_Result::failure( __( 'SMTP is not configured.', 'ibg-client-outreach' ), false );
		}

		self::load_phpmailer();

		$mailer = new \PHPMailer\PHPMailer\PHPMailer( true );

		try {
			$encryption = (string) $this->settings->get( 'smtp_encryption', 'tls' );

			$mailer->isSMTP();
			$mailer->Host        = (string) $this->settings->get( 'smtp_host', '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$mailer->Port        = (int) $this->settings->get( 'smtp_port', 587 ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$mailer->SMTPAuth    = (bool) $this->settings->get( 'smtp_auth', true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$mailer->SMTPSecure  = 'none' === $encryption ? '' : $encryption; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$mailer->SMTPAutoTLS = 'none' !== $encryption; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$mailer->Timeout     = 15; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$mailer->CharSet     = 'UTF-8'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$mailer->XMailer     = ' '; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

			if ( $mailer->SMTPAuth ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$mailer->Username = (string) $this->settings->get( 'smtp_username', '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$mailer->Password = $this->password(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			}

			$mailer->setFrom( $message->get_from_email(), $message->get_from_name() );
			$mailer->addAddress( $message->get_to_email(), $message->get_to_name() );
			if ( '' !== $message->get_reply_to() ) {
				$mailer->addReplyTo( $message->get_reply_to() );
			}

			$mailer->Subject = $message->get_subject(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			if ( $message->is_html() ) {
				$mailer->isHTML( true );
				$mailer->Body    = $message->get_html_body(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$mailer->AltBody = $message->get_text_body(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			} else {
				$mailer->isHTML( false );
				$mailer->Body = $message->get_text_body(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			}

			foreach ( $message->get_headers() as $name => $value ) {
				$mailer->addCustomHeader( (string) $name, (string) $value );
			}

			/**
			 * Last chance to adjust the PHPMailer instance (DKIM signing, debug output, …).
			 *
			 * @param \PHPMailer\PHPMailer\PHPMailer $mailer  Mailer.
			 * @param Email_Message                  $message Message.
			 */
			do_action( 'ibg_outreach_smtp_before_send', $mailer, $message );

			$mailer->send();

			return Send_Result::success( trim( (string) $mailer->getLastMessageID(), '<>' ) );
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			$error = $e->getMessage();
			// Credential problems will not fix themselves; connection problems might.
			$retryable = ! preg_match( '/authenticat|invalid login|535|username and password/i', $error );
			return Send_Result::failure( $error, $retryable );
		} catch ( \Throwable $e ) {
			return Send_Result::failure( $e->getMessage(), true );
		}
	}

	/**
	 * Effective password: constant wins over the (encrypted) stored value.
	 *
	 * @return string
	 */
	private function password(): string {
		if ( defined( self::PASSWORD_CONSTANT ) ) {
			return (string) constant( self::PASSWORD_CONSTANT );
		}
		return Secrets::decrypt( (string) $this->settings->get( 'smtp_password', '' ) );
	}

	/**
	 * Load PHPMailer from core.
	 *
	 * @return void
	 */
	private static function load_phpmailer(): void {
		if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer' ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}
	}
}
