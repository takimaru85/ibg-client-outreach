<?php
/**
 * Template service: validation, sanitisation, plain-text generation.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Templates;

use IBG\Outreach\Email\Html_To_Text;
use IBG\Outreach\Email\Merge_Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template_Service
 */
final class Template_Service {

	/**
	 * Warnings from the last save (e.g. unknown merge tags).
	 *
	 * @var string[]
	 */
	private array $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param Template_Repository $templates Repository.
	 * @param Merge_Tags          $tags      Merge tags.
	 */
	public function __construct(
		private readonly Template_Repository $templates,
		private readonly Merge_Tags $tags
	) {}

	/**
	 * Warnings from the last save (and clear them).
	 *
	 * @return string[]
	 */
	public function take_warnings(): array {
		$w              = $this->warnings;
		$this->warnings = array();
		return $w;
	}

	/**
	 * HTML allowed in template bodies: post HTML plus what email clients need.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public function allowed_html(): array {
		$allowed = wp_kses_allowed_html( 'post' );

		$email_attrs = array(
			'style'       => true,
			'align'       => true,
			'valign'      => true,
			'width'       => true,
			'height'      => true,
			'bgcolor'     => true,
			'border'      => true,
			'cellpadding' => true,
			'cellspacing' => true,
			'role'        => true,
		);
		foreach ( array( 'table', 'tr', 'td', 'th', 'tbody', 'thead', 'tfoot', 'div', 'p', 'span', 'a', 'img', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'center' ) as $tag ) {
			$allowed[ $tag ] = array_merge( $allowed[ $tag ] ?? array(), $email_attrs );
		}
		$allowed['a']['target'] = true;
		$allowed['a']['rel']    = true;

		/**
		 * Filter HTML allowed in email templates.
		 *
		 * @param array $allowed KSES allowed HTML.
		 */
		return apply_filters( 'ibg_outreach_template_allowed_html', $allowed );
	}

	/**
	 * Create or update a template.
	 *
	 * Data: name, subject, body_html, body_text, is_active, regenerate_text (bool).
	 *
	 * @param array<string, mixed> $data Raw input.
	 * @param int                  $id   Existing id or 0.
	 * @return Email_Template|\WP_Error
	 */
	public function save( array $data, int $id = 0 ): Email_Template|\WP_Error {
		$this->warnings = array();

		$existing = $id > 0 ? $this->templates->find( $id ) : null;
		if ( $id > 0 && ! $existing ) {
			return new \WP_Error( 'not_found', __( 'Template not found.', 'ibg-client-outreach' ) );
		}

		$template = $existing ?? new Email_Template();

		$template->name    = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 190 );
		$template->subject = mb_substr( sanitize_text_field( (string) ( $data['subject'] ?? '' ) ), 0, 255 );

		if ( '' === $template->name ) {
			return new \WP_Error( 'name_required', __( 'A template name is required.', 'ibg-client-outreach' ) );
		}
		if ( '' === $template->subject ) {
			return new \WP_Error( 'subject_required', __( 'A subject is required.', 'ibg-client-outreach' ) );
		}

		$template->body_html = $this->sanitize_html( (string) ( $data['body_html'] ?? '' ) );
		if ( '' === trim( wp_strip_all_tags( $template->body_html ) ) ) {
			return new \WP_Error( 'body_required', __( 'The email body is empty.', 'ibg-client-outreach' ) );
		}

		$text = sanitize_textarea_field( (string) ( $data['body_text'] ?? '' ) );
		if ( '' === trim( $text ) || ! empty( $data['regenerate_text'] ) ) {
			$text = Html_To_Text::convert( $template->body_html );
		}
		$template->body_text = $text;

		$template->is_active = ! empty( $data['is_active'] );

		foreach ( array( $template->subject, $template->body_html, $template->body_text ) as $content ) {
			foreach ( $this->tags->find_unknown( $content ) as $unknown ) {
				$this->warnings[ $unknown ] = sprintf(
					/* translators: %s: merge tag name */
					__( 'Unknown merge tag {{%s}} will be replaced with nothing when sending.', 'ibg-client-outreach' ),
					$unknown
				);
			}
		}
		$this->warnings = array_values( $this->warnings );

		if ( $existing ) {
			if ( ! $this->templates->update( $template ) ) {
				return new \WP_Error( 'db_error', __( 'The template could not be saved.', 'ibg-client-outreach' ) );
			}
		} elseif ( ! $this->templates->insert( $template ) ) {
			return new \WP_Error( 'db_error', __( 'The template could not be saved.', 'ibg-client-outreach' ) );
		}

		return $template;
	}

	/**
	 * Duplicate a template as an inactive copy.
	 *
	 * @param int $id Template id.
	 * @return Email_Template|\WP_Error
	 */
	public function duplicate( int $id ): Email_Template|\WP_Error {
		$source = $this->templates->find( $id );
		if ( ! $source ) {
			return new \WP_Error( 'not_found', __( 'Template not found.', 'ibg-client-outreach' ) );
		}

		$copy            = clone $source;
		$copy->id        = 0;
		$copy->is_active = false;
		/* translators: %s: template name */
		$copy->name = mb_substr( sprintf( __( '%s (copy)', 'ibg-client-outreach' ), $source->name ), 0, 190 );

		if ( ! $this->templates->insert( $copy ) ) {
			return new \WP_Error( 'db_error', __( 'The template could not be duplicated.', 'ibg-client-outreach' ) );
		}
		return $copy;
	}

	/**
	 * Delete templates that are not referenced by a campaign.
	 *
	 * @param int[] $ids Template ids.
	 * @return array{deleted:int, blocked:int}
	 */
	public function delete( array $ids ): array {
		$deletable = array();
		$blocked   = 0;
		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			if ( $this->templates->count_campaigns_using( $id ) > 0 ) {
				++$blocked;
			} else {
				$deletable[] = $id;
			}
		}
		return array(
			'deleted' => $this->templates->delete_many( $deletable ),
			'blocked' => $blocked,
		);
	}

	/**
	 * The example outreach template.
	 *
	 * @return array<string, mixed> Data for save().
	 */
	public function example_template_data(): array {
		$html = '<p>Hi {{first_name|there}},</p>'
			. '<p>I&#8217;m {{from_name}}, a WordPress developer specialising in website development, maintenance, redesigns, performance optimisation and WordPress support.</p>'
			. '<p>I&#8217;m currently taking on a few new projects and wanted to see if {{company}} needs help with its website.</p>'
			. '<p>If you have anything you&#8217;d like to discuss, feel free to reply to this email.</p>'
			. '<p>Thanks,<br>{{from_name}}<br>{{business_name}}</p>';

		return array(
			'name'      => __( 'Website help introduction', 'ibg-client-outreach' ),
			'subject'   => 'Website help for {{company|your business}}',
			'body_html' => $html,
			'body_text' => '',
			'is_active' => true,
		);
	}

	/**
	 * Sanitise template HTML with the email-safe allow list.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function sanitize_html( string $html ): string {
		return trim( wp_kses( $html, $this->allowed_html() ) );
	}
}
