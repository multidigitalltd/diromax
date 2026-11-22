<?php
/**
 * The Diromax theme against the Multi Digital web defaults (standard 15.2–15.6):
 * lead permissions and CRM fields, retention, export, notification retry,
 * Turnstile validation, privacy notice version and the footer.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

final class ThemeWebDefaultsTest extends PortalTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->assertTrue( function_exists( 'dx_process_lead' ), 'The theme is loaded in the tests.' );
		$_POST = array();
	}

	public function tear_down(): void {
		$_POST = array();
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_wp_mail', 5 );
		delete_option( 'dx_lead_audit' );
		parent::tear_down();
	}

	/**
	 * Standard 15.2.3: leads need a capability of their own.
	 */
	public function test_only_lead_handlers_can_open_leads(): void {
		$lead    = $this->lead();
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$manager = $this->manager();

		$this->assertFalse( user_can( $author, 'edit_post', $lead ) );
		$this->assertFalse( user_can( $editor, 'edit_post', $lead ) );
		$this->assertFalse( dx_can_manage_leads( $editor ) );
		$this->assertTrue( user_can( $admin, 'edit_post', $lead ) );
		$this->assertTrue( user_can( $manager, 'edit_post', $lead ), 'Project managers open cases from leads.' );
		$this->assertFalse( user_can( $admin, get_post_type_object( 'dx_lead' )->cap->create_posts ), 'Leads come only from the form.' );
	}

	/**
	 * Regression (PR review, and red on a fresh CI database): a role created
	 * after the theme's first grant, as the portal's dx_manager is, still
	 * gets lead access on the next request.
	 */
	public function test_a_role_created_later_still_gets_lead_access(): void {
		$roles = static fn( array $role_names ): array => array_merge( $role_names, array( 'dx_late_role' ) );
		add_filter( 'dx_lead_roles', $roles );

		dx_leads_grant_caps();
		add_role( 'dx_late_role', 'Late', array( 'read' => true ) );
		dx_leads_grant_caps();

		$this->assertTrue( get_role( 'dx_late_role' )->has_cap( 'edit_others_dx_leads' ) );

		remove_filter( 'dx_lead_roles', $roles );
		remove_role( 'dx_late_role' );
	}

	public function test_a_submitted_lead_gets_status_consent_campaign_and_a_lean_email(): void {
		$_POST = array(
			'nonce'      => wp_create_nonce( 'dx_lead' ),
			'dx_name'    => 'רונית כהן',
			'dx_phone'   => '050-1234567',
			'dx_message' => 'פרטים אישיים שלא צריכים להיות במייל',
			'dx_utm'     => 'google / cpc / roof<script>',
		);

		$this->assertTrue( dx_process_lead() );

		$lead = get_posts(
			array(
				'post_type'   => 'dx_lead',
				'post_status' => 'any',
			)
		)[0];

		$this->assertSame( 'new', get_post_meta( $lead->ID, 'dx_status', true ) );
		$this->assertSame( 'google / cpc / roofscript', get_post_meta( $lead->ID, 'dx_utm', true ) );

		$consent = json_decode( (string) get_post_meta( $lead->ID, 'dx_consent', true ), true );
		$this->assertSame( 'contact_request', $consent['basis'] );
		$this->assertSame( dx_privacy_version(), $consent['policy'] );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '050-1234567', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'פרטים אישיים', $this->mails[0]['message'], 'Details stay in the dashboard (standard 15.2.6).' );
		$this->assertStringContainsString( 'post=' . $lead->ID, $this->mails[0]['message'] );
	}

	public function test_a_failed_notification_is_retried_and_the_lead_is_kept(): void {
		add_filter( 'pre_wp_mail', '__return_false', 5 );
		$lead = $this->lead();

		$this->assertFalse( dx_lead_notify( $lead ) );
		$this->assertSame( '1', get_post_meta( $lead, 'dx_mail_tries', true ) );
		$this->assertNotFalse( wp_next_scheduled( 'dx_lead_notify_retry', array( $lead ) ) );

		update_post_meta( $lead, 'dx_mail_tries', DX_LEAD_MAIL_TRIES - 1 );
		wp_clear_scheduled_hook( 'dx_lead_notify_retry', array( $lead ) );
		dx_lead_notify( $lead );
		$this->assertFalse( wp_next_scheduled( 'dx_lead_notify_retry', array( $lead ) ), 'No retry after the last attempt.' );
		$this->assertNotNull( get_post( $lead ), 'A failed email never loses the lead.' );
	}

	public function test_status_and_notes_need_the_nonce_and_the_capability(): void {
		$lead  = $this->lead();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_POST = array(
			'dx_lead_crm_nonce' => wp_create_nonce( 'dx_lead_crm_' . $lead ),
			'dx_status'         => 'closed',
		);
		dx_lead_save_crm( $lead );
		$this->assertSame( 'new', get_post_meta( $lead, 'dx_status', true ), 'An editor cannot change a lead.' );

		wp_set_current_user( $admin );
		$_POST = array(
			'dx_lead_crm_nonce' => 'forged',
			'dx_status'         => 'closed',
		);
		dx_lead_save_crm( $lead );
		$this->assertSame( 'new', get_post_meta( $lead, 'dx_status', true ), 'A forged nonce changes nothing.' );

		$_POST = array(
			'dx_lead_crm_nonce' => wp_create_nonce( 'dx_lead_crm_' . $lead ),
			'dx_status'         => 'not-a-status',
			'dx_notes'          => 'חזרנו אליו',
		);
		dx_lead_save_crm( $lead );
		$this->assertSame( 'new', get_post_meta( $lead, 'dx_status', true ), 'Unknown statuses are ignored.' );
		$this->assertSame( 'חזרנו אליו', get_post_meta( $lead, 'dx_notes', true ) );

		$_POST['dx_status'] = 'progress';
		dx_lead_save_crm( $lead );
		$this->assertSame( 'progress', get_post_meta( $lead, 'dx_status', true ) );
		$this->assertSame( array( 'status', 'notes' ), array_column( get_option( 'dx_lead_audit' ), 'action' ) );
	}

	public function test_old_leads_are_deleted_after_the_retention_period(): void {
		$old    = $this->lead( gmdate( 'Y-m-d H:i:s', (int) strtotime( '-30 months' ) ) );
		$recent = $this->lead( gmdate( 'Y-m-d H:i:s', (int) strtotime( '-2 months' ) ) );

		$this->assertSame( 1, dx_lead_cleanup() );
		$this->assertNull( get_post( $old ) );
		$this->assertNotNull( get_post( $recent ) );
		$this->assertSame( 'retention', get_option( 'dx_lead_audit' )[0]['action'] );

		set_theme_mod( 'dx_lead_retention', 1 );
		$this->assertSame( 1, dx_lead_cleanup() );
		remove_theme_mod( 'dx_lead_retention' );
	}

	/**
	 * Regression (PR review): the export walks every batch instead of stopping at one.
	 */
	public function test_the_lead_export_includes_every_batch(): void {
		$batch = static fn(): int => 2;
		add_filter( 'dx_lead_export_batch', $batch );

		foreach ( range( 1, 5 ) as $n ) {
			update_post_meta( $this->lead(), 'dx_name', 'ליד ' . $n );
		}

		$csv = dx_leads_csv();
		remove_filter( 'dx_lead_export_batch', $batch );

		foreach ( range( 1, 5 ) as $n ) {
			$this->assertStringContainsString( 'ליד ' . $n, $csv );
		}
		$this->assertSame( 6, substr_count( trim( $csv ), "\n" ) + 1, 'Header and five rows.' );
	}

	public function test_the_lead_export_defuses_formulas(): void {
		$lead = $this->lead();
		update_post_meta( $lead, 'dx_name', '=HYPERLINK("http://x")' );
		update_post_meta( $lead, 'dx_phone', '+972501234567' );

		$csv = dx_leads_csv();

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$this->assertStringContainsString( "\"'=HYPERLINK(\"\"http://x\"\")\"", $csv );
		$this->assertStringContainsString( "'+972501234567", $csv );
		$this->assertSame( 'שם', dx_csv_cell( 'שם' ) );
	}

	/**
	 * Standard 15.3.5: success alone is not enough.
	 */
	public function test_turnstile_checks_success_action_and_hostname(): void {
		add_filter( 'dx_turnstile_secret', static fn(): string => 'secret-for-tests', 20 );
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$reply = static function ( array $body ): void {
			remove_all_filters( 'pre_http_request' );
			add_filter(
				'pre_http_request',
				static fn() => array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $body ),
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				)
			);
		};

		$reply( array( 'success' => true, 'action' => 'dx_lead', 'hostname' => $host ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( dx_turnstile_verify( 'token', 'dx_lead' ) );

		$reply( array( 'success' => true, 'action' => 'other', 'hostname' => $host ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( dx_turnstile_verify( 'token', 'dx_lead' ) );

		$reply( array( 'success' => true, 'action' => 'dx_lead', 'hostname' => 'evil.example' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( dx_turnstile_verify( 'token', 'dx_lead' ) );

		$reply( array( 'success' => false ) );
		$this->assertFalse( dx_turnstile_verify( 'token', 'dx_lead' ) );

		$this->assertFalse( dx_turnstile_verify( '', 'dx_lead' ) );

		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'http_request_failed', 'offline' ) );
		$this->assertFalse( dx_turnstile_verify( 'token', 'dx_lead' ), 'A network failure rejects (standard 15.3.6).' );
	}

	public function test_the_footer_has_the_credit_the_legal_links_and_privacy_settings(): void {
		$privacy = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'מדיניות פרטיות',
			)
		);
		$terms   = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'תנאי שימוש',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $privacy );
		set_theme_mod( 'dx_terms_page', $terms );

		$footer = $this->render( 'template-parts/site-footer' );

		$this->assertMatchesRegularExpression( '#<a class="dx-footer__credit" href="https://m-d.co.il" target="_blank" rel="noopener">#', $footer );
		$this->assertStringContainsString( (string) get_permalink( $privacy ), $footer );
		$this->assertStringContainsString( (string) get_permalink( $terms ), $footer );
		$this->assertStringContainsString( 'data-dx-privacy-open hidden', $footer );

		set_theme_mod( 'dx_credit', false );
		$this->assertStringNotContainsString( 'm-d.co.il', $this->render( 'template-parts/site-footer' ) );

		remove_theme_mod( 'dx_credit' );
		remove_theme_mod( 'dx_terms_page' );
		delete_option( 'wp_page_for_privacy_policy' );
	}

	public function test_the_privacy_notice_is_static_and_versioned_by_the_policy(): void {
		$this->assertSame( '1', dx_privacy_version(), 'No policy page yet.' );

		$privacy = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'wp_page_for_privacy_policy', $privacy );
		$first = dx_privacy_version();

		wp_update_post(
			array(
				'ID'                => $privacy,
				'post_content'      => 'עודכן',
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 120 ),
			)
		);
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 120 ) ), array( 'ID' => $privacy ) );
		clean_post_cache( $privacy );

		$this->assertNotSame( $first, dx_privacy_version(), 'A changed policy shows the notice again.' );

		$notice = $this->render( 'template-parts/privacy-notice' );
		$this->assertStringContainsString( 'role="dialog"', $notice );
		$this->assertStringContainsString( ' hidden>', $notice, 'Hidden until the script decides: cache-safe and no layout shift.' );
		$this->assertStringContainsString( (string) get_privacy_policy_url(), $notice );

		delete_option( 'wp_page_for_privacy_policy' );
	}

	/**
	 * A stored lead.
	 *
	 * @param string $date_gmt Creation time, UTC.
	 * @return int
	 */
	private function lead( string $date_gmt = '' ): int {
		$args = array(
			'post_type'   => 'dx_lead',
			'post_status' => 'publish',
			'post_title'  => 'ליד',
		);

		if ( '' !== $date_gmt ) {
			$args['post_date']     = $date_gmt;
			$args['post_date_gmt'] = $date_gmt;
		}

		$id = self::factory()->post->create( $args );
		update_post_meta( $id, 'dx_name', 'ליד' );
		update_post_meta( $id, 'dx_phone', '050-0000000' );
		update_post_meta( $id, 'dx_status', 'new' );

		return $id;
	}

	/**
	 * Output of a theme template part.
	 *
	 * @param string $slug Template part.
	 * @return string
	 */
	private function render( string $slug ): string {
		ob_start();
		get_template_part( $slug );

		return (string) ob_get_clean();
	}
}
