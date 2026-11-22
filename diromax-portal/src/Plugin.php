<?php
/**
 * Plugin wiring.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal;

use Diromax\Portal\Data\AcknowledgementRepository;
use Diromax\Portal\Data\AuditLog;
use Diromax\Portal\Data\BudgetRepository;
use Diromax\Portal\Data\CaseRepository;
use Diromax\Portal\Data\DashboardRepository;
use Diromax\Portal\Data\DecisionRepository;
use Diromax\Portal\Data\DocRequestRepository;
use Diromax\Portal\Data\DocumentRepository;
use Diromax\Portal\Data\EventRepository;
use Diromax\Portal\Data\LedgerRepository;
use Diromax\Portal\Data\MailLog;
use Diromax\Portal\Data\MemberRepository;
use Diromax\Portal\Data\Schema;
use Diromax\Portal\Data\SignatureRepository;
use Diromax\Portal\Data\SignRequestRepository;
use Diromax\Portal\Data\TaskRepository;
use Diromax\Portal\Data\TokenRepository;
use Diromax\Portal\Data\UpdateRepository;
use Diromax\Portal\Security\AccessPolicy;
use Diromax\Portal\Security\Capabilities;
use Diromax\Portal\Security\DocumentStore;
use Diromax\Portal\Security\RateLimiter;
use Diromax\Portal\Service\CaseService;
use Diromax\Portal\Service\DocumentService;
use Diromax\Portal\Service\LedgerService;
use Diromax\Portal\Service\LoginService;
use Diromax\Portal\Service\MailRenderer;
use Diromax\Portal\Service\Notifier;
use Diromax\Portal\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the object graph once and registers the hooks.
 *
 * There is no global mutable state beyond this single instance; tests replace
 * it with reset() to inject a frozen clock.
 */
final class Plugin {

	/** Daily retention job. */
	public const CRON_CLEANUP = 'dx_portal_cleanup';

	/** Notification worker. */
	public const CRON_NOTIFY = 'dx_portal_notify';

	/**
	 * The instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	public readonly \wpdb $db;
	/**
	 * Cases.
	 *
	 * @var CaseRepository
	 */
	public readonly CaseRepository $cases;
	/**
	 * Members.
	 *
	 * @var MemberRepository
	 */
	public readonly MemberRepository $members;
	/**
	 * Ledger.
	 *
	 * @var LedgerRepository
	 */
	public readonly LedgerRepository $ledger;
	/**
	 * Budget.
	 *
	 * @var BudgetRepository
	 */
	public readonly BudgetRepository $budget;
	/**
	 * Updates.
	 *
	 * @var UpdateRepository
	 */
	public readonly UpdateRepository $updates;
	/**
	 * Documents.
	 *
	 * @var DocumentRepository
	 */
	public readonly DocumentRepository $documents;
	/**
	 * Audit log.
	 *
	 * @var AuditLog
	 */
	public readonly AuditLog $audit;
	/**
	 * Events.
	 *
	 * @var EventRepository
	 */
	public readonly EventRepository $events;
	/**
	 * Mail log.
	 *
	 * @var MailLog
	 */
	public readonly MailLog $mail_log;
	/**
	 * Tokens.
	 *
	 * @var TokenRepository
	 */
	public readonly TokenRepository $tokens;
	/**
	 * Decisions.
	 *
	 * @var DecisionRepository
	 */
	public readonly DecisionRepository $decisions;
	/**
	 * Agreement acknowledgements.
	 *
	 * @var AcknowledgementRepository
	 */
	public readonly AcknowledgementRepository $acknowledgements;
	/**
	 * Document requests.
	 *
	 * @var DocRequestRepository
	 */
	public readonly DocRequestRepository $doc_requests;
	/**
	 * Signatures.
	 *
	 * @var SignatureRepository
	 */
	public readonly SignatureRepository $signatures;
	/**
	 * Signature requests.
	 *
	 * @var SignRequestRepository
	 */
	public readonly SignRequestRepository $sign_requests;
	/**
	 * Team tasks.
	 *
	 * @var TaskRepository
	 */
	public readonly TaskRepository $tasks;
	/**
	 * Dashboard queries.
	 *
	 * @var DashboardRepository
	 */
	public readonly DashboardRepository $dashboard;
	/**
	 * Authorization.
	 *
	 * @var AccessPolicy
	 */
	public readonly AccessPolicy $policy;
	/**
	 * Encrypted file store.
	 *
	 * @var DocumentStore
	 */
	public readonly DocumentStore $store;
	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	public readonly RateLimiter $limiter;
	/**
	 * Case use cases.
	 *
	 * @var CaseService
	 */
	public readonly CaseService $case_service;
	/**
	 * Ledger use cases.
	 *
	 * @var LedgerService
	 */
	public readonly LedgerService $ledger_service;
	/**
	 * Document use cases.
	 *
	 * @var DocumentService
	 */
	public readonly DocumentService $document_service;
	/**
	 * Sign-in and invitations.
	 *
	 * @var LoginService
	 */
	public readonly LoginService $login;
	/**
	 * Email rendering.
	 *
	 * @var MailRenderer
	 */
	public readonly MailRenderer $renderer;
	/**
	 * Notifications.
	 *
	 * @var Notifier
	 */
	public readonly Notifier $notifier;

	/**
	 * Build the graph.
	 *
	 * @param Clock $clock Clock.
	 */
	private function __construct( public readonly Clock $clock ) {
		global $wpdb;

		$this->db        = $wpdb;
		$this->cases     = new CaseRepository( $wpdb );
		$this->members   = new MemberRepository( $wpdb );
		$this->ledger    = new LedgerRepository( $wpdb );
		$this->budget    = new BudgetRepository( $wpdb );
		$this->updates   = new UpdateRepository( $wpdb );
		$this->documents = new DocumentRepository( $wpdb );
		$this->audit     = new AuditLog( $wpdb );
		$this->events    = new EventRepository( $wpdb );
		$this->mail_log  = new MailLog( $wpdb );
		$this->tokens    = new TokenRepository( $wpdb );
		$this->decisions = new DecisionRepository( $wpdb );

		$this->acknowledgements = new AcknowledgementRepository( $wpdb );
		$this->dashboard        = new DashboardRepository( $wpdb );
		$this->tasks            = new TaskRepository( $wpdb );
		$this->doc_requests     = new DocRequestRepository( $wpdb );
		$this->signatures       = new SignatureRepository( $wpdb );
		$this->sign_requests    = new SignRequestRepository( $wpdb );
		$this->policy           = new AccessPolicy( $this->members, $clock );
		$this->store            = new DocumentStore();
		$this->limiter          = new RateLimiter( $clock );

		$this->renderer         = new MailRenderer();
		$this->notifier         = new Notifier( $this );
		$this->case_service     = new CaseService( $this );
		$this->ledger_service   = new LedgerService( $this );
		$this->document_service = new DocumentService( $this );
		$this->login            = new LoginService( $this );
	}

	/**
	 * The instance, built on first use.
	 *
	 * @return self
	 */
	public static function instance(): self {
		self::$instance ??= new self( new Clock() );

		return self::$instance;
	}

	/**
	 * Rebuild the instance with a given clock (tests).
	 *
	 * @param Clock $clock Clock.
	 * @return self
	 */
	public static function reset( Clock $clock ): self {
		self::$instance = new self( $clock );

		return self::$instance;
	}

	/**
	 * Hooked to plugins_loaded.
	 */
	public static function boot(): void {
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'diromax-portal', false, dirname( plugin_basename( DX_PORTAL_FILE ) ) . '/languages' );
			},
			0
		);
		Schema::maybe_upgrade();
		Hooks::register( self::instance() );
	}

	/**
	 * Activation: tables, roles, rewrite rules, scheduled jobs.
	 */
	public static function activate(): void {
		Schema::install();
		Capabilities::install();
		Portal\Router::add_rules();
		flush_rewrite_rules();
		self::schedule();
	}

	/**
	 * Deactivation: stop scheduled jobs, drop rewrite rules. Data stays.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_NOTIFY );
		wp_clear_scheduled_hook( self::CRON_CLEANUP );
		flush_rewrite_rules();
	}

	/**
	 * Make sure both scheduled jobs exist.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_NOTIFY ) ) {
			wp_schedule_event( time() + 60, 'dx_portal_five_minutes', self::CRON_NOTIFY );
		}

		if ( ! wp_next_scheduled( self::CRON_CLEANUP ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::CRON_CLEANUP );
		}
	}
}
