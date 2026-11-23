<?php
/**
 * Quote comparisons: quotes gathered by the manager, chosen by the client in
 * the portal, recorded in the ledger as a commitment (spec 24).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Schema;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Service\QuoteService;
use WP_Error;

final class QuotesTest extends PortalTestCase {

	/**
	 * A quote form.
	 *
	 * @param string $supplier Supplier.
	 * @param string $amount   Shekels.
	 * @param bool   $best     Recommended.
	 * @return array<string, string>
	 */
	private static function offer( string $supplier, string $amount, bool $best = false ): array {
		return array(
			'supplier'    => $supplier,
			'amount'      => $amount,
			'duration'    => '12 שבועות',
			'note'        => '',
			'recommended' => $best ? '1' : '',
		);
	}

	/**
	 * A case with a client and a draft comparison on the consultants line.
	 *
	 * @return array{0:CaseRecord, 1:int, 2:int, 3:int} Case, manager, client, comparison.
	 */
	private function drafted(): array {
		$manager = $this->manager();
		$client  = $this->client( 'owner' . wp_rand() . '@example.org' );
		$case    = $this->case_for( $manager, $client );
		$id      = ( new QuoteService( $this->app ) )->create(
			$case,
			array(
				'title'    => 'מודד מוסמך',
				'category' => 'consultants',
				'note'     => 'מדידה לצורך היתר',
				'due_on'   => '2026-12-01',
			),
			$manager
		);
		$this->assertIsInt( $id );

		return array( $case, $manager, $client, $id );
	}

	/**
	 * A comparison sent to the client with three quotes.
	 *
	 * @return array{0:CaseRecord, 1:int, 2:int, 3:int, 4:array<int, int>} Case, manager, client, comparison, quotes.
	 */
	private function sent(): array {
		[ $case, $manager, $client, $id ] = $this->drafted();
		$service                          = new QuoteService( $this->app );
		$quotes                           = array();

		foreach ( array( self::offer( 'מדידות כהן', '4,800' ), self::offer( 'גיאו-מפות', '5,500', true ), self::offer( 'מודד צפון', '6,200' ) ) as $input ) {
			$quote = $service->add_offer( $case, $id, $input, null, $manager );
			$this->assertIsInt( $quote );
			$quotes[] = $quote;
		}

		$this->assertTrue( $service->send( $case, $id, false, $manager ) );

		return array( $case, $manager, $client, $id, $quotes );
	}

	/**
	 * The error code of a refusal, or '' when it was allowed.
	 *
	 * @param mixed $result Service result.
	 * @return string
	 */
	private function code( mixed $result ): string {
		return $result instanceof WP_Error ? (string) $result->get_error_code() : '';
	}

	public function test_quotes_are_sent_with_their_documents_only_when_there_are_two(): void {
		[ $case, $manager, $client, $id ] = $this->drafted();
		$service                          = new QuoteService( $this->app );

		$first = $service->add_offer( $case, $id, self::offer( 'מדידות כהן', '4,800' ), $this->sample_pdf(), $manager );
		$this->assertIsInt( $first );
		$document = $this->app->documents->find( (int) $this->app->quotes->find_offer( $first )['document_id'] );
		$this->assertNotNull( $document );
		$this->assertSame( array( 'quote', false ), array( $document->kind, $document->visible ), 'A draft is the staff\'s.' );

		$this->assertSame( array( 'send' => 'too_few' ), $service->send( $case, $id, true, $manager )->get_error_data()['fields'] ?? array() );
		$this->assertNull( $service->for_client( $case ), 'Nothing to show the client yet.' );

		$this->assertIsInt( $service->add_offer( $case, $id, self::offer( 'גיאו-מפות', '5,500', true ), null, $manager ) );
		$this->assertTrue( $service->send( $case, $id, true, $manager ) );
		$this->assertTrue( $this->app->documents->find( $document->id )->visible ?? false, 'Sent: the quotes can be opened.' );
		$this->assertStringContainsString( 'הצעות מחיר לבחירתך', (string) end( $this->mails )['subject'] );
		$this->assertSame( 'dx_not_found', $this->code( $service->add_offer( $case, $id, self::offer( 'מאוחר', '1' ), null, $manager ) ), 'No quotes after sending.' );

		$view = ( new CaseView( $this->app ) )->build( $case, $client, 'quotes' )['quotes'];
		$this->assertIsArray( $view );
		$this->assertSame( array( 'מדידות כהן', 'גיאו-מפות' ), array_column( $view[0]['offers'], 'supplier' ), 'Cheapest first.' );
		$this->assertSame( array( true, false ), array_column( $view[0]['offers'], 'lowest' ) );
		$this->assertNotSame( '', $view[0]['offers'][0]['document'] );
	}

	public function test_the_client_chooses_once_and_the_manager_hears_of_it(): void {
		[ $case, $manager, $client, $id, $quotes ] = $this->sent();
		$service                                   = new QuoteService( $this->app );

		$this->assertSame( array( 'confirm' => 'required' ), $service->choose( $case, $id, $quotes[1], $client, '', false )->get_error_data()['fields'] ?? array() );
		$this->assertSame( array( 'offer' => 'required' ), $service->choose( $case, $id, 999999, $client, '', true )->get_error_data()['fields'] ?? array() );

		$this->assertTrue( $service->choose( $case, $id, $quotes[1], $client, 'מעדיפים את המומלצת', true ) );
		$comparison = $this->app->quotes->find_comparison( $id );
		$this->assertSame( array( 'chosen', $quotes[1], $client, 'מעדיפים את המומלצת' ), array( $comparison['status'] ?? '', $comparison['chosen_offer_id'] ?? 0, $comparison['chosen_by'] ?? 0, $comparison['chosen_note'] ?? '' ) );
		$this->assertStringContainsString( 'הלקוח בחר הצעה', (string) end( $this->mails )['subject'] );
		$this->assertSame( get_userdata( $manager )->user_email, end( $this->mails )['to'] );

		$this->assertSame( 'dx_not_pending', $this->code( $service->choose( $case, $id, $quotes[0], $client, '', true ) ), 'Chosen once.' );
		$this->assertSame( 'dx_not_found', $this->code( $service->withdraw( $case, $id, $manager ) ), 'A choice is not withdrawn.' );
	}

	public function test_a_viewer_cannot_choose_and_a_stranger_does_not_see_it(): void {
		[ $case, , , $id, $quotes ] = $this->sent();
		$service                    = new QuoteService( $this->app );
		$viewer                     = $this->client();
		$this->app->members->upsert( $case->id, $viewer, MemberRole::Viewer, true, $this->clock->mysql() );

		$this->assertSame( 'dx_forbidden', $this->code( $service->choose( $case, $id, $quotes[0], $viewer, '', true ) ) );
		$this->assertSame( 'dx_not_found', $this->code( $service->choose( $case, $id, $quotes[0], $this->client(), '', true ) ) );
		$this->assertSame( 'open', $this->app->quotes->find_comparison( $id )['status'] ?? '' );
	}

	public function test_the_choice_is_recorded_as_a_commitment_once(): void {
		[ $case, $manager, $client, $id, $quotes ] = $this->sent();
		$service                                   = new QuoteService( $this->app );
		$this->assertSame( 'dx_not_found', $this->code( $service->commit( $case, $id, 'client', $manager ) ), 'Not chosen yet.' );
		$this->assertTrue( $service->choose( $case, $id, $quotes[1], $client, '', true ) );

		$entry = $service->commit( $case, $id, 'client', $manager );
		$this->assertIsInt( $entry );
		$row = $this->app->ledger->find( $entry );
		$this->assertNotNull( $row );
		$this->assertSame( array( EntryType::Expense, EntryStatus::Committed, 550000, 'consultants', 'גיאו-מפות', 'מודד מוסמך' ), array( $row->entry->type, $row->entry->status, $row->entry->total, $row->entry->category, $row->entry->payee, $row->entry->description ) );
		$this->assertSame( $entry, $this->app->quotes->find_comparison( $id )['entry_id'] ?? 0 );
		$this->assertSame( 'dx_not_found', $this->code( $service->commit( $case, $id, 'client', $manager ) ), 'Recorded once.' );
		$this->assertCount( 1, array_filter( $this->app->ledger->for_case( $case->id, false ), static fn( $entry_row ): bool => EntryType::Expense === $entry_row->entry->type ) );
	}

	public function test_bad_input_and_other_cases_are_refused(): void {
		[ $case, $manager, , $id ] = $this->drafted();
		$service                   = new QuoteService( $this->app );

		$this->assertSame(
			array(
				'title'    => 'required',
				'category' => 'invalid',
			),
			$service->create(
				$case,
				array(
					'title'    => '',
					'category' => 'nope',
				),
				$manager
			)->get_error_data()['fields'] ?? array()
		);
		$this->assertSame( array( 'amount' => 'invalid' ), $service->add_offer( $case, $id, self::offer( 'x', '-5' ), null, $manager )->get_error_data()['fields'] ?? array() );

		[ $elsewhere, $its_manager ] = $this->drafted();
		$this->assertSame( 'dx_not_found', $this->code( $service->add_offer( $elsewhere, $id, self::offer( 'x', '100' ), null, $its_manager ) ) );
		$this->assertSame( 'dx_not_found', $this->code( $service->send( $elsewhere, $id, false, $its_manager ) ) );

		foreach ( array( $this->manager(), $this->client() ) as $actor ) {
			$this->assertSame( 'dx_forbidden', $this->code( $service->create( $case, array( 'title' => 'x' ), $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->add_offer( $case, $id, self::offer( 'x', '100' ), null, $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->send( $case, $id, false, $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->withdraw( $case, $id, $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->commit( $case, $id, 'client', $actor ) ) );
		}

		$this->assertInstanceOf( CaseRecord::class, $this->app->case_service->set_status( $case, 'closed', $manager ) );
		$this->assertSame( 'dx_closed', $this->code( $service->create( $this->app->cases->find( $case->id ) ?? $case, array( 'title' => 'x' ), $manager ) ) );
	}

	public function test_a_closed_case_takes_no_choice(): void {
		// Regression (PR #20 review): a client could still choose after the case was closed.
		[ $case, $manager, $client, $id, $quotes ] = $this->sent();
		$this->assertInstanceOf( CaseRecord::class, $this->app->case_service->set_status( $case, 'closed', $manager ) );
		$closed = $this->app->cases->find( $case->id );
		$this->assertNotNull( $closed );

		$this->assertSame( 'dx_closed', $this->code( ( new QuoteService( $this->app ) )->choose( $closed, $id, $quotes[0], $client, '', true ) ) );
		$this->assertSame( 'open', $this->app->quotes->find_comparison( $id )['status'] ?? '' );
	}

	public function test_the_email_leaves_prices_out_when_the_office_hides_amounts(): void {
		// Regression (PR #20 review): the quotes email showed prices despite the setting.
		\Diromax\Portal\Settings::save( array( 'hide_amounts' => '1' ) );
		[ $case, $manager, , $id ] = $this->drafted();
		$service                   = new QuoteService( $this->app );
		$this->assertIsInt( $service->add_offer( $case, $id, self::offer( 'מדידות כהן', '4,800' ), null, $manager ) );
		$this->assertIsInt( $service->add_offer( $case, $id, self::offer( 'גיאו-מפות', '5,500' ), null, $manager ) );
		$this->assertTrue( $service->send( $case, $id, true, $manager ) );

		$body = (string) end( $this->mails )['message'];
		$this->assertStringContainsString( 'גיאו-מפות', $body );
		$this->assertStringNotContainsString( '4,800', $body );
		$this->assertStringNotContainsString( '5,500', $body );
	}

	public function test_the_staff_tab_counts_what_waits_and_deleting_the_case_removes_it(): void {
		global $wpdb;
		[ $case, $manager, $client, $id, $quotes ] = $this->sent();
		wp_set_current_user( $manager );
		$pages = new Pages( $this->app );
		$this->assertSame( array( 'quotes', 1, 'הצעות מחיר' ), array( $pages->case_data( $case, $manager, 'quotes' )['tab'], $pages->case_data( $case, $manager, 'quotes' )['counts']['quotes'], $pages->case_data( $case, $manager, 'quotes' )['tabs']['quotes'] ) );

		$this->assertTrue( ( new QuoteService( $this->app ) )->choose( $case, $id, $quotes[0], $client, '', true ) );
		$this->assertSame( 1, $this->app->quotes->count_waiting( $case->id ), 'Chosen, not yet recorded: waits on the manager.' );

		$this->assertIsInt( $this->app->case_service->delete( $case, $this->admin() ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d', Schema::table( 'offers' ), $case->id ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d', Schema::table( 'comparisons' ), $case->id ) ) );
	}
}
