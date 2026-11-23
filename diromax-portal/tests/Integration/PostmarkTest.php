<?php
/**
 * Every email through Postmark, and bounce reports (spec 17.1–17.2).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Portal\Webhooks;
use Diromax\Portal\Service\Mailer;
use Diromax\Portal\Service\MailMessage;
use Diromax\Portal\Service\Postmark;
use Diromax\Portal\Support\Integrations;
use Diromax\Portal\Support\MailTrace;
use WP_Error;
use WP_REST_Request;

final class PostmarkTest extends PortalTestCase {

	/** Webhook key used in these tests. */
	private const KEY = 'test-webhook-key-0123456789abcdefghij';

	/**
	 * Requests that reached the API.
	 *
	 * @var array<int, array{url:string, args:array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * Settings the tests switch on.
	 *
	 * @var array<string, string>
	 */
	private array $config = array();

	/**
	 * The API's next answer.
	 *
	 * @var array{status:int, body:array<string, mixed>}
	 */
	private array $answer = array(
		'status' => 200,
		'body'   => array(
			'ErrorCode' => 0,
			'MessageID' => 'b7bc2f4a-e38e-4336-af7d-e6c392c2f817',
		),
	);

	public function set_up(): void {
		parent::set_up();
		add_filter( 'dx_portal_mail_config', array( $this, 'config' ), 10, 2 );
		add_filter( 'pre_http_request', array( $this, 'api' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'dx_portal_mail_config', array( $this, 'config' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'api' ), 10 );
		MailTrace::reset();
		parent::tear_down();
	}

	/**
	 * Test settings.
	 *
	 * @param string $value Value from wp-config.
	 * @param string $name  Setting.
	 * @return string
	 */
	public function config( $value, $name ): string {
		return $this->config[ $name ] ?? (string) $value;
	}

	/**
	 * Stand-in for the Postmark API.
	 *
	 * @param false|array<string, mixed> $pre  Short-circuit.
	 * @param array<string, mixed>       $args Request.
	 * @param string                     $url  URL.
	 * @return false|array<string, mixed>
	 */
	public function api( $pre, $args, $url ) {
		if ( ! str_starts_with( (string) $url, 'https://api.postmarkapp.com/' ) ) {
			return $pre;
		}

		$this->requests[] = array(
			'url'  => (string) $url,
			'args' => $args,
		);

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $this->answer['body'] ),
			'response' => array(
				'code'    => $this->answer['status'],
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Send for real through wp_mail(), with Postmark on.
	 */
	private function through_postmark(): void {
		$this->config['token'] = 'server-token-for-tests';
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
	}

	/**
	 * The JSON the API received last.
	 *
	 * @return array<string, mixed>
	 */
	private function sent(): array {
		$this->assertNotEmpty( $this->requests, 'Nothing reached Postmark.' );
		$body = json_decode( (string) end( $this->requests )['args']['body'], true );
		$this->assertIsArray( $body );

		return $body;
	}

	public function test_without_a_token_wordpress_sends_as_before(): void {
		$this->assertFalse( Postmark::configured() );
		$this->assertNull( ( new Postmark() )->send( null, array( 'to' => 'a@example.org' ) ) );
		$this->assertTrue( ( new Postmark() )->send( true, array() ), 'An earlier answer stands.' );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_portal_email_goes_out_through_the_api_with_both_bodies(): void {
		$this->through_postmark();
		$manager = get_userdata( $this->manager() );
		$message = new MailMessage( 'עדכון בתיק', '<p>שלום</p>', 'שלום, גרסת טקסט' );

		$this->assertTrue( Mailer::send( 'cohen@example.org', $message, $manager ? $manager : null ) );

		$request = end( $this->requests );
		$this->assertSame( 'https://api.postmarkapp.com/email', $request['url'] );
		$this->assertSame( 'server-token-for-tests', $request['args']['headers']['X-Postmark-Server-Token'] );

		$body = $this->sent();
		$this->assertSame( 'cohen@example.org', $body['To'] );
		$this->assertSame( 'עדכון בתיק', $body['Subject'] );
		$this->assertSame( '<p>שלום</p>', $body['HtmlBody'] );
		$this->assertSame( 'שלום, גרסת טקסט', $body['TextBody'], 'The text version the portal wrote, not a stripped copy.' );
		$this->assertStringContainsString( 'דירומקס · דניאל לוי', $body['From'] );
		$this->assertStringContainsString( (string) $manager->user_email, $body['ReplyTo'] );
		$this->assertSame( 'outbound', $body['MessageStream'] );
		$this->assertFalse( $body['TrackOpens'] );
		$this->assertSame( 'None', $body['TrackLinks'], 'Login and signing links must reach the client unchanged.' );
		$this->assertSame( '', MailTrace::$text, 'The text is cleared after the send.' );
	}

	public function test_the_sender_name_from_the_settings_is_on_every_email(): void {
		$this->through_postmark();
		$this->config['from']      = 'portal@diromax.co.il';
		$this->config['from_name'] = 'דירומקס השבחת נכסים';
		$manager                   = get_userdata( $this->manager() );

		$this->assertTrue( Mailer::send( 'cohen@example.org', new MailMessage( 'עדכון בתיק', '<p>שלום</p>', 'שלום' ), $manager ? $manager : null ) );
		$this->assertSame( 'דירומקס השבחת נכסים · דניאל לוי <portal@diromax.co.il>', $this->sent()['From'] );

		$this->assertTrue( Mailer::send( 'cohen@example.org', new MailMessage( 'קוד כניסה', '<p>123456</p>', '123456' ), null ) );
		$this->assertSame( 'דירומקס השבחת נכסים <portal@diromax.co.il>', $this->sent()['From'] );

		$this->assertTrue( wp_mail( 'admin@example.org', 'איפוס סיסמה', 'קישור לאיפוס' ) );
		$this->assertSame( 'דירומקס השבחת נכסים <portal@diromax.co.il>', $this->sent()['From'], 'WordPress\'s own email, not "WordPress".' );
		$this->assertSame( 'Contact Form', apply_filters( 'wp_mail_from_name', 'Contact Form' ), 'A name another plugin set is kept.' );
	}

	public function test_without_a_sender_name_wordpress_email_goes_out_under_the_brand(): void {
		$this->assertSame( 'דירומקס', apply_filters( 'wp_mail_from_name', 'WordPress' ), 'With or without Postmark.' );
	}

	public function test_mail_from_other_plugins_goes_through_the_api_too(): void {
		$this->through_postmark();
		$this->config['from'] = 'portal@diromax.co.il';

		$this->assertTrue( wp_mail( 'lead@example.org', 'פנייה חדשה מהאתר', "שורה 1\nשורה 2", array( 'From: Contact Form <wordpress@other.example>', 'X-Lead: 42' ) ) );

		$body = $this->sent();
		$this->assertSame( "שורה 1\nשורה 2", $body['TextBody'] );
		$this->assertArrayNotHasKey( 'HtmlBody', $body );
		$this->assertSame( 'Contact Form <portal@diromax.co.il>', $body['From'], 'The verified sender address, the plugin\'s sender name.' );
		$this->assertSame( 'Contact Form <wordpress@other.example>', $body['ReplyTo'] );
		$this->assertSame(
			array(
				array(
					'Name'  => 'x-lead',
					'Value' => '42',
				),
			),
			$body['Headers']
		);
	}

	public function test_attachments_travel_and_line_breaks_never_reach_a_header(): void {
		$file    = $this->sample_pdf();
		$payload = Postmark::payload(
			array(
				'to'          => array( "a@example.org\r\nBcc: evil@example.org" ),
				'subject'     => 'מסמך',
				'message'     => '<p>x</p>',
				'headers'     => "Content-Type: text/html\r\nCc: b@example.org\r\nX-Note: one\rtwo",
				'attachments' => array( 'הסכם.pdf' => (string) $file['tmp_name'] ),
			),
			'דירומקס <portal@diromax.co.il>',
			'outbound',
			''
		);

		$this->assertIsArray( $payload );
		$this->assertSame( 'a@example.orgBcc: evil@example.org', $payload['To'], 'One mangled address, no extra header.' );
		$this->assertSame( 'b@example.org', $payload['Cc'] );
		$this->assertSame( 'onetwo', $payload['Headers'][0]['Value'] );
		$this->assertSame( 'x', $payload['TextBody'], 'A text version is made when none was given.' );
		$this->assertSame( 'הסכם.pdf', $payload['Attachments'][0]['Name'] );
		$this->assertSame( 'application/pdf', $payload['Attachments'][0]['ContentType'] );
		$this->assertSame( (string) file_get_contents( (string) $file['tmp_name'] ), base64_decode( $payload['Attachments'][0]['Content'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test comparison.

		$this->assertInstanceOf( WP_Error::class, Postmark::payload( array( 'to' => '' ), 'x <a@b.c>', 'outbound', '' ) );
	}

	public function test_an_api_error_fails_the_send_and_is_reported_like_wordpress_does(): void {
		$this->through_postmark();
		$this->answer = array(
			'status' => 422,
			'body'   => array(
				'ErrorCode' => 300,
				'Message'   => 'Invalid email request',
			),
		);
		$failed       = array();
		$listen       = static function ( WP_Error $error ) use ( &$failed ): void {
			$failed[] = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $listen );

		$this->assertFalse( Mailer::send( 'cohen@example.org', new MailMessage( 'x', '<p>x</p>', 'x' ), null ) );
		remove_action( 'wp_mail_failed', $listen );

		$this->assertSame( array( 'Postmark 422/300: Invalid email request' ), $failed );
		$this->assertSame( '', MailTrace::take_id() );
	}

	public function test_the_message_id_is_logged_and_a_bounce_report_marks_it(): void {
		$this->through_postmark();
		$this->config['webhook_key'] = self::KEY;
		$client                      = $this->client();
		$case                        = $this->case_for( $this->manager(), $client );

		$this->assertTrue( $this->app->notifier->send_to_member( $case, $client, 'cohen@example.org', 'digest', new MailMessage( 'סיכום', '<p>x</p>', 'x' ), '' ) );
		$this->assertSame( 'sent', $this->app->mail_log->for_case( $case->id )[0]['status'] );

		$request = new WP_REST_Request( 'POST', '/' . Webhooks::NAMESPACE . '/postmark/events' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'authorization', 'Basic ' . base64_encode( 'postmark:' . self::KEY ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.
		$request->set_body(
			(string) wp_json_encode(
				array(
					'RecordType'  => 'Bounce',
					'Type'        => 'HardBounce',
					'MessageID'   => 'b7bc2f4a-e38e-4336-af7d-e6c392c2f817',
					'Description' => 'The server was unable to deliver your message.',
					'Email'       => 'cohen@example.org',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$row = $this->app->mail_log->for_case( $case->id )[0];
		$this->assertSame( 'bounced', $row['status'] );
		$this->assertStringStartsWith( 'HardBounce:', $row['error'] );

		$dashboard = ( new Pages( $this->app ) )->dashboard_data( $case->manager_id );
		$this->assertSame( array( 'cohen@example.org' ), array_column( $dashboard['bounced'], 'recipient' ) );
	}

	public function test_webhooks_refuse_requests_without_the_key(): void {
		$hooks   = new Webhooks( $this->app );
		$request = new WP_REST_Request( 'POST', '/' . Webhooks::NAMESPACE . '/postmark/events' );

		$this->assertSame( 401, $hooks->authorized( $request )->get_error_data()['status'], 'No key configured: nothing gets in.' );

		$this->config['webhook_key'] = 'short';
		$request->set_header( 'authorization', 'Basic ' . base64_encode( 'postmark:short' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.
		$this->assertInstanceOf( WP_Error::class, $hooks->authorized( $request ), 'A key under 32 characters is not accepted.' );

		$this->config['webhook_key'] = self::KEY;
		$request->set_header( 'authorization', 'Basic ' . base64_encode( 'postmark:wrong-key-wrong-key-wrong-key-wrong' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.

		for ( $i = 0; $i < 17; $i++ ) {
			$this->assertInstanceOf( WP_Error::class, $hooks->authorized( $request ) );
		}

		$request->set_header( 'authorization', 'Basic ' . base64_encode( 'postmark:' . self::KEY ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.
		$this->assertTrue( $hooks->authorized( $request ) );

		$request->set_header( 'authorization', 'Basic ' . base64_encode( 'postmark:nope' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.
		$hooks->authorized( $request );
		$request->set_header( 'authorization', 'Basic ' . base64_encode( 'postmark:' . self::KEY ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.
		$this->assertSame( 429, $hooks->authorized( $request )->get_error_data()['status'], 'After 20 failures even the right key waits.' );
	}

	public function test_both_routes_have_a_permission_check_and_skip_application_passwords(): void {
		$routes = rest_get_server()->get_routes();

		foreach ( array( 'events', 'inbound' ) as $path ) {
			$route = '/' . Webhooks::NAMESPACE . '/postmark/' . $path;
			$this->assertArrayHasKey( $route, $routes );
			$this->assertIsCallable( $routes[ $route ][0]['permission_callback'] );
			$this->assertSame( array( 'POST' => true ), $routes[ $route ][0]['methods'] );
		}

		$open = Webhooks::allow_in_aios( array( 'diromax', 42 ) );
		$this->assertSame( array( 'diromax', 'diromax-portal', '/diromax-portal' ), $open, 'Kept open when AIOS blocks REST for visitors; junk dropped.' );
		$this->assertContains( 'diromax-portal', (array) apply_filters( 'aios_whitelisted_rest_routes', array() ), 'Registered on the AIOS filter.' );

		$before = $_SERVER['REQUEST_URI'] ?? null;

		try {
			$_SERVER['REQUEST_URI'] = '/wp-json/' . Webhooks::NAMESPACE . '/postmark/inbound';
			$this->assertFalse( Webhooks::not_for_application_passwords( true ) );
			$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
			$this->assertTrue( Webhooks::not_for_application_passwords( true ), 'Other REST requests keep application passwords.' );
		} finally {
			if ( null === $before ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $before;
			}
		}
	}

	public function test_the_settings_screen_shows_the_setup_without_the_key(): void {
		$this->assertSame(
			array( 'token', 'from' ),
			Integrations::save(
				array(
					'token' => 'server-token-for-tests',
					'from'  => 'portal@diromax.co.il',
				)
			)
		);
		$key = Integrations::new_webhook_key();

		$status = Pages::mail_status();
		$this->assertTrue( $status['postmark'] );
		$this->assertSame( 'portal@diromax.co.il', $status['fields']['from']['value'] );
		$this->assertTrue( $status['fields']['token']['set'] );
		$this->assertSame( '', $status['fields']['token']['value'], 'A secret is never handed to the screen.' );
		$this->assertTrue( $status['webhook_key'] );
		$this->assertFalse( $status['inbound'] );
		$this->assertStringContainsString( 'postmark:KEY@', $status['inbound_url'] );
		$this->assertStringNotContainsString( $key, (string) wp_json_encode( $status ) );
		$this->assertStringNotContainsString( 'server-token-for-tests', (string) wp_json_encode( $status ) );
	}
}
