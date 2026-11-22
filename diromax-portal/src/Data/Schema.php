<?php
/**
 * Database schema and migrations.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables.
 *
 * Tables are InnoDB with foreign keys between them (standard 6.6.2). dbDelta
 * cannot manage foreign keys, so they are added in a second step that checks
 * information_schema first and is safe to re-run.
 */
final class Schema {

	/** Schema version; bump it with every change below. */
	public const VERSION = '4';

	/** Option holding the installed schema version. */
	public const OPTION = 'dx_portal_db_version';

	/** Every table, without prefix. */
	public const TABLES = array( 'cases', 'case_members', 'ledger', 'budget', 'updates', 'documents', 'audit', 'events', 'mail_log', 'login_tokens', 'decisions', 'acknowledgements', 'tasks', 'doc_requests', 'sign_requests', 'signatures', 'messages' );

	/**
	 * Full table name.
	 *
	 * @param string $name Short name from TABLES.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'dx_' . $name;
	}

	/**
	 * Install or upgrade when the stored version differs.
	 */
	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( self::OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Create or update every table, then the constraints.
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::definitions() );
		self::add_foreign_keys();
		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Drop every table. Used only by uninstall.php when a purge is requested.
	 */
	public static function drop_all(): void {
		global $wpdb;

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall purge; no API exists.

		foreach ( self::TABLES as $name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall purge of our own tables.
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall purge; no API exists.
		delete_option( self::OPTION );
	}

	/**
	 * CREATE TABLE statements in dbDelta's format.
	 *
	 * @return string[]
	 */
	private static function definitions(): array {
		global $wpdb;

		$c       = $wpdb->get_charset_collate() . ' ENGINE=InnoDB';
		$cases   = self::table( 'cases' );
		$members = self::table( 'case_members' );
		$ledger  = self::table( 'ledger' );
		$budget  = self::table( 'budget' );
		$updates = self::table( 'updates' );
		$docs    = self::table( 'documents' );
		$audit   = self::table( 'audit' );
		$events  = self::table( 'events' );
		$mail    = self::table( 'mail_log' );
		$tokens  = self::table( 'login_tokens' );
		$choices = self::table( 'decisions' );
		$acks    = self::table( 'acknowledgements' );
		$tasks   = self::table( 'tasks' );
		$asks    = self::table( 'doc_requests' );
		$signreq = self::table( 'sign_requests' );
		$signs   = self::table( 'signatures' );
		$msgs    = self::table( 'messages' );

		return array(
			"CREATE TABLE {$cases} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(24) NOT NULL,
  number varchar(24) NOT NULL,
  title varchar(200) NOT NULL,
  property_type varchar(60) NOT NULL DEFAULT '',
  track varchar(120) NOT NULL DEFAULT '',
  scope varchar(300) NOT NULL DEFAULT '',
  fee_terms varchar(300) NOT NULL DEFAULT '',
  signed_on date DEFAULT NULL,
  manager_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  stage tinyint(3) unsigned NOT NULL DEFAULT 1,
  stages text NOT NULL,
  summary text DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  closed_at datetime DEFAULT NULL,
  lead_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  UNIQUE KEY number (number),
  KEY manager_status (manager_id,status),
  KEY lead_id (lead_id)
) {$c};",
			"CREATE TABLE {$members} (
  case_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  role varchar(20) NOT NULL DEFAULT 'owner',
  notify tinyint(1) NOT NULL DEFAULT 1,
  digest tinyint(1) NOT NULL DEFAULT 0,
  digest_sent_at datetime DEFAULT NULL,
  invited_at datetime DEFAULT NULL,
  last_seen_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (case_id,user_id),
  KEY user_id (user_id)
) {$c};",
			"CREATE TABLE {$ledger} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  type varchar(20) NOT NULL,
  status varchar(20) NOT NULL,
  entry_date date NOT NULL,
  due_date date DEFAULT NULL,
  category varchar(40) NOT NULL DEFAULT '',
  payee varchar(120) NOT NULL DEFAULT '',
  description varchar(200) NOT NULL,
  net bigint(20) NOT NULL DEFAULT 0,
  vat bigint(20) NOT NULL DEFAULT 0,
  total bigint(20) NOT NULL DEFAULT 0,
  vat_rate smallint(5) unsigned NOT NULL DEFAULT 0,
  funding varchar(20) NOT NULL DEFAULT '',
  pay_to varchar(20) NOT NULL DEFAULT '',
  method varchar(20) NOT NULL DEFAULT '',
  reference varchar(60) NOT NULL DEFAULT '',
  payment_details text DEFAULT NULL,
  internal_note text DEFAULT NULL,
  visible tinyint(1) NOT NULL DEFAULT 1,
  request_id bigint(20) unsigned DEFAULT NULL,
  document_id bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_by bigint(20) unsigned NOT NULL,
  updated_at datetime NOT NULL,
  voided_by bigint(20) unsigned DEFAULT NULL,
  voided_at datetime DEFAULT NULL,
  void_reason varchar(200) NOT NULL DEFAULT '',
  reminders_sent tinyint(3) unsigned NOT NULL DEFAULT 0,
  overdue_reminders tinyint(3) unsigned NOT NULL DEFAULT 0,
  last_reminder_on date DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY case_date (case_id,entry_date),
  KEY type_status_due (type,status,due_date),
  KEY case_type (case_id,type,status),
  KEY request_id (request_id),
  KEY document_id (document_id)
) {$c};",
			"CREATE TABLE {$budget} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  category varchar(40) NOT NULL,
  planned bigint(20) NOT NULL DEFAULT 0,
  note varchar(200) NOT NULL DEFAULT '',
  sort smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY case_category (case_id,category)
) {$c};",
			"CREATE TABLE {$updates} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL,
  body text NOT NULL,
  stage tinyint(3) unsigned DEFAULT NULL,
  visible tinyint(1) NOT NULL DEFAULT 1,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY case_created (case_id,created_at)
) {$c};",
			"CREATE TABLE {$docs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(24) NOT NULL,
  case_id bigint(20) unsigned NOT NULL,
  update_id bigint(20) unsigned DEFAULT NULL,
  kind varchar(20) NOT NULL,
  title varchar(200) NOT NULL,
  original_name varchar(200) NOT NULL,
  stored_name char(48) NOT NULL,
  mime varchar(100) NOT NULL,
  size bigint(20) unsigned NOT NULL,
  sha256 char(64) NOT NULL,
  key_version smallint(5) unsigned NOT NULL DEFAULT 1,
  visible tinyint(1) NOT NULL DEFAULT 1,
  sign_required tinyint(1) NOT NULL DEFAULT 0,
  request_id bigint(20) unsigned DEFAULT NULL,
  uploaded_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  UNIQUE KEY stored_name (stored_name),
  KEY case_kind (case_id,kind),
  KEY update_id (update_id)
) {$c};",
			"CREATE TABLE {$audit} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(60) NOT NULL,
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned DEFAULT NULL,
  details text DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY case_created (case_id,created_at),
  KEY created_at (created_at)
) {$c};",
			"CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  kind varchar(30) NOT NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  immediate tinyint(1) NOT NULL DEFAULT 0,
  status varchar(10) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  sent_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status_case (status,case_id),
  KEY case_id (case_id)
) {$c};",
			"CREATE TABLE {$mail} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  recipient varchar(190) NOT NULL,
  template varchar(40) NOT NULL,
  subject varchar(255) NOT NULL,
  batch_key char(40) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL,
  error varchar(255) NOT NULL DEFAULT '',
  provider_id varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY case_created (case_id,created_at),
  KEY batch_user (batch_key,user_id),
  KEY provider_id (provider_id),
  KEY status_created (status,created_at),
  KEY created_at (created_at)
) {$c};",
			"CREATE TABLE {$tokens} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  purpose varchar(10) NOT NULL,
  token_hash char(64) NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY user_id (user_id),
  KEY expires_at (expires_at)
) {$c};",
			"CREATE TABLE {$choices} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL,
  body text NOT NULL,
  amount bigint(20) DEFAULT NULL,
  document_id bigint(20) unsigned DEFAULT NULL,
  due_date date DEFAULT NULL,
  status varchar(12) NOT NULL DEFAULT 'pending',
  fingerprint char(64) NOT NULL DEFAULT '',
  answer_note varchar(500) NOT NULL DEFAULT '',
  answered_by bigint(20) unsigned DEFAULT NULL,
  answered_at datetime DEFAULT NULL,
  withdraw_reason varchar(200) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY case_status (case_id,status),
  KEY status_updated (status,updated_at),
  KEY document_id (document_id)
) {$c};",
			"CREATE TABLE {$acks} (
  document_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  case_id bigint(20) unsigned NOT NULL,
  sha256 char(64) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (document_id,user_id),
  KEY case_created (case_id,created_at)
) {$c};",
			"CREATE TABLE {$tasks} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL,
  assignee_id bigint(20) unsigned NOT NULL,
  due_date date DEFAULT NULL,
  done_at datetime DEFAULT NULL,
  done_by bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY case_done (case_id,done_at),
  KEY assignee_done (assignee_id,done_at,due_date)
) {$c};",
			"CREATE TABLE {$asks} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL,
  note varchar(500) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'open',
  document_id bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  closed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY case_status (case_id,status)
) {$c};",
			"CREATE TABLE {$signreq} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  document_id bigint(20) unsigned NOT NULL,
  signer_name varchar(100) NOT NULL,
  signer_email varchar(190) NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  token_hash char(64) NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'pending',
  code_hash char(64) NOT NULL DEFAULT '',
  code_expires_at datetime DEFAULT NULL,
  code_attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  codes_sent tinyint(3) unsigned NOT NULL DEFAULT 0,
  codes_window_at datetime DEFAULT NULL,
  verified_at datetime DEFAULT NULL,
  expires_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  closed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY case_document (case_id,document_id),
  KEY status_expires (status,expires_at)
) {$c};",
			"CREATE TABLE {$signs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  subject_type varchar(10) NOT NULL,
  subject_id bigint(20) unsigned NOT NULL,
  request_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  signer_name varchar(100) NOT NULL,
  signer_email varchar(190) NOT NULL,
  id_number_enc text DEFAULT NULL,
  image_enc mediumtext DEFAULT NULL,
  sha256 char(64) NOT NULL,
  method varchar(10) NOT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  verified_at datetime DEFAULT NULL,
  signed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY case_subject (case_id,subject_type,subject_id),
  KEY user_id (user_id)
) {$c};",
			"CREATE TABLE {$msgs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  provider_id varchar(64) NOT NULL,
  sender_name varchar(100) NOT NULL DEFAULT '',
  sender_email varchar(190) NOT NULL,
  subject varchar(255) NOT NULL DEFAULT '',
  body mediumtext NOT NULL,
  files text NOT NULL,
  received_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_id (provider_id),
  KEY case_received (case_id,received_at),
  KEY user_id (user_id)
) {$c};",
		);
	}

	/**
	 * Foreign keys and checks: constraint name => [table, definition].
	 *
	 * @return array<string, array{0:string, 1:string}>
	 */
	private static function constraints(): array {
		global $wpdb;

		$cases = self::table( 'cases' );
		$users = $wpdb->users;

		return array(
			'dx_members_case'   => array( 'case_members', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_members_user'   => array( 'case_members', "FOREIGN KEY (user_id) REFERENCES {$users} (ID) ON DELETE CASCADE" ),
			'dx_ledger_case'    => array( 'ledger', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_ledger_request' => array( 'ledger', 'FOREIGN KEY (request_id) REFERENCES ' . self::table( 'ledger' ) . ' (id) ON DELETE SET NULL' ),
			'dx_ledger_doc'     => array( 'ledger', 'FOREIGN KEY (document_id) REFERENCES ' . self::table( 'documents' ) . ' (id) ON DELETE SET NULL' ),
			'dx_ledger_amounts' => array( 'ledger', 'CHECK (net >= 0 AND vat >= 0 AND total >= 0)' ),
			'dx_budget_case'    => array( 'budget', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_budget_planned' => array( 'budget', 'CHECK (planned >= 0)' ),
			'dx_updates_case'   => array( 'updates', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_docs_case'      => array( 'documents', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_docs_update'    => array( 'documents', 'FOREIGN KEY (update_id) REFERENCES ' . self::table( 'updates' ) . ' (id) ON DELETE SET NULL' ),
			'dx_audit_case'     => array( 'audit', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_events_case'    => array( 'events', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_mail_case'      => array( 'mail_log', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_tokens_user'    => array( 'login_tokens', "FOREIGN KEY (user_id) REFERENCES {$users} (ID) ON DELETE CASCADE" ),
			'dx_cases_stage'    => array( 'cases', 'CHECK (stage BETWEEN 1 AND 7)' ),
			'dx_choices_case'   => array( 'decisions', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_choices_doc'    => array( 'decisions', 'FOREIGN KEY (document_id) REFERENCES ' . self::table( 'documents' ) . ' (id) ON DELETE SET NULL' ),
			'dx_choices_amount' => array( 'decisions', 'CHECK (amount IS NULL OR amount >= 0)' ),
			'dx_acks_case'      => array( 'acknowledgements', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_acks_doc'       => array( 'acknowledgements', 'FOREIGN KEY (document_id) REFERENCES ' . self::table( 'documents' ) . ' (id) ON DELETE CASCADE' ),
			'dx_tasks_case'     => array( 'tasks', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_asks_case'      => array( 'doc_requests', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_asks_doc'       => array( 'doc_requests', 'FOREIGN KEY (document_id) REFERENCES ' . self::table( 'documents' ) . ' (id) ON DELETE SET NULL' ),
			'dx_signreq_case'   => array( 'sign_requests', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_signreq_doc'    => array( 'sign_requests', 'FOREIGN KEY (document_id) REFERENCES ' . self::table( 'documents' ) . ' (id) ON DELETE CASCADE' ),
			'dx_signs_case'     => array( 'signatures', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_msgs_case'      => array( 'messages', "FOREIGN KEY (case_id) REFERENCES {$cases} (id) ON DELETE CASCADE" ),
			'dx_msgs_user'      => array( 'messages', "FOREIGN KEY (user_id) REFERENCES {$users} (ID) ON DELETE SET NULL" ),
		);
	}

	/**
	 * Add each constraint that is not there yet.
	 *
	 * A failure (e.g. a host whose users table is MyISAM) is logged and does not
	 * stop activation: the application enforces the same rules, the constraints
	 * are a second layer.
	 */
	private static function add_foreign_keys(): void {
		global $wpdb;

		foreach ( self::constraints() as $name => $constraint ) {
			$table = self::table( $constraint[0] );

			if ( self::constraint_exists( $table, $name ) ) {
				continue;
			}

			// Identifiers come from the constants above; values cannot be bound in DDL.
			$sql = "ALTER TABLE {$table} ADD CONSTRAINT {$name} {$constraint[1]}";

			if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- DDL built from constants only.
				Logger::warning( 'schema.constraint_failed', array( 'constraint' => $name ) );
			}
		}
	}

	/**
	 * Does the named constraint exist on the table?
	 *
	 * @param string $table Full table name.
	 * @param string $name  Constraint name.
	 * @return bool
	 */
	private static function constraint_exists( string $table, string $name ): bool {
		global $wpdb;

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- information_schema lookup during migration.
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s',
				$table,
				$name
			)
		);

		return (int) $found > 0;
	}
}
