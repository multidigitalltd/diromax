<?php
/**
 * Mini CRM for leads (Multi Digital standard 15.2).
 *
 * The lead post type itself lives in leads.php. This file adds what the
 * standard asks of a lead store: a dedicated capability, status and notes,
 * the consent record, campaign source, a retention period with automatic
 * deletion, an audited CSV export, and a notification that is retried.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Version of the capability grant; bump it to re-run dx_leads_grant_caps(). */
const DX_LEAD_CAPS_VERSION = '1';

/** Audit entries kept. */
const DX_LEAD_AUDIT_MAX = 1000;

/** Notification attempts before giving up. */
const DX_LEAD_MAIL_TRIES = 3;

/**
 * Lead statuses: key => label.
 *
 * @return array<string,string>
 */
function dx_lead_statuses() {
	return array(
		'new'        => __( 'חדש', 'diromax' ),
		'progress'   => __( 'בטיפול', 'diromax' ),
		'closed'     => __( 'נסגר', 'diromax' ),
		'irrelevant' => __( 'לא רלוונטי', 'diromax' ),
	);
}

/**
 * Primitive capabilities of the lead post type (capability_type dx_lead).
 *
 * @return string[]
 */
function dx_lead_caps() {
	return array(
		'edit_dx_leads',
		'edit_others_dx_leads',
		'edit_published_dx_leads',
		'edit_private_dx_leads',
		'publish_dx_leads',
		'read_private_dx_leads',
		'delete_dx_leads',
		'delete_others_dx_leads',
		'delete_published_dx_leads',
		'delete_private_dx_leads',
	);
}

/**
 * Give the lead capabilities to the roles that handle leads.
 *
 * Administrators, and the portal's project managers (they open cases from
 * leads). Authors, editors and contributors no longer see personal data
 * from the contact form. Filter dx_lead_roles to change the list.
 */
function dx_leads_grant_caps() {
	if ( DX_LEAD_CAPS_VERSION === get_option( 'dx_lead_caps_version' ) ) {
		return;
	}

	$roles = (array) apply_filters( 'dx_lead_roles', array( 'administrator', 'dx_manager' ) );

	foreach ( $roles as $role_name ) {
		$role = get_role( (string) $role_name );

		if ( $role ) {
			foreach ( dx_lead_caps() as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	update_option( 'dx_lead_caps_version', DX_LEAD_CAPS_VERSION, false );
}
add_action( 'init', 'dx_leads_grant_caps', 20 );

/**
 * May the user handle leads at all?
 *
 * @param int $user_id User; 0 for the current user.
 * @return bool
 */
function dx_can_manage_leads( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	return $user_id > 0 && user_can( $user_id, 'edit_others_dx_leads' );
}

/* ---------------------------------------------------------------
 * Stored with the lead
 * ------------------------------------------------------------ */

/**
 * Campaign source from the submitted form: "source / medium / campaign".
 *
 * The forms script fills the hidden field from the landing URL's utm_* parameters.
 * Only letters, digits and a few separators are kept.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function dx_lead_utm( $value ) {
	$value = is_string( $value ) ? wp_unslash( $value ) : '';
	$value = (string) preg_replace( '/[^\p{L}\p{N} _.\/\-]/u', '', $value );

	return mb_substr( trim( $value ), 0, 200 );
}

/**
 * The consent record kept with every lead (standard 5.2).
 *
 * The form shows the privacy note and links the policy; the record keeps
 * which version of the policy was in force and when the visitor submitted.
 * There is no marketing consent: the form only asks to be called back.
 *
 * @return string JSON.
 */
function dx_lead_consent_record() {
	return (string) wp_json_encode(
		array(
			'basis'   => 'contact_request',
			'notice'  => 'form_privacy_note',
			'policy'  => dx_privacy_version(),
			'at'      => gmdate( 'c' ),
			'channel' => 'website_form',
		)
	);
}

/**
 * Store the CRM fields of a new lead.
 *
 * @param int    $post_id Lead.
 * @param string $utm     Campaign source.
 */
function dx_lead_store_crm( $post_id, $utm ) {
	update_post_meta( $post_id, 'dx_status', 'new' );
	update_post_meta( $post_id, 'dx_consent', dx_lead_consent_record() );

	if ( '' !== $utm ) {
		update_post_meta( $post_id, 'dx_utm', $utm );
	}
}

/* ---------------------------------------------------------------
 * Audit log (standard 15.2.3)
 * ------------------------------------------------------------ */

/**
 * Record who did what with leads. Kept in one option, newest first.
 *
 * @param string $action  view, status, notes, export, delete, retention.
 * @param int    $lead_id Lead, 0 for bulk actions.
 * @param string $details Short detail.
 */
function dx_lead_audit( $action, $lead_id = 0, $details = '' ) {
	$log = get_option( 'dx_lead_audit', array() );
	$log = is_array( $log ) ? $log : array();

	array_unshift(
		$log,
		array(
			'at'      => gmdate( 'Y-m-d H:i:s' ),
			'user'    => get_current_user_id(),
			'action'  => sanitize_key( $action ),
			'lead'    => (int) $lead_id,
			'details' => mb_substr( (string) $details, 0, 200 ),
		)
	);

	update_option( 'dx_lead_audit', array_slice( $log, 0, DX_LEAD_AUDIT_MAX ), false );
}

/**
 * Opening a lead counts as viewing it.
 *
 * @param WP_Screen $screen Current screen.
 */
function dx_lead_audit_view( $screen ) {
	if ( 'post' !== $screen->base || 'dx_lead' !== $screen->post_type ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen; the ID is only logged.
	$lead_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

	if ( $lead_id ) {
		dx_lead_audit( 'view', $lead_id );
	}
}
add_action( 'current_screen', 'dx_lead_audit_view' );

/**
 * Deleting a lead is logged before it goes.
 *
 * @param int     $post_id Post.
 * @param WP_Post $post    Post object.
 */
function dx_lead_audit_delete( $post_id, $post ) {
	if ( $post instanceof WP_Post && 'dx_lead' === $post->post_type && ! doing_action( 'dx_lead_cleanup' ) ) {
		dx_lead_audit( 'delete', (int) $post_id );
	}
}
add_action( 'before_delete_post', 'dx_lead_audit_delete', 10, 2 );

/* ---------------------------------------------------------------
 * Status and notes on the edit screen
 * ------------------------------------------------------------ */

/**
 * The CRM box on a lead.
 */
function dx_lead_meta_boxes() {
	add_meta_box( 'dx-lead-crm', __( 'טיפול בליד', 'diromax' ), 'dx_lead_crm_box', 'dx_lead', 'side', 'high' );
}
add_action( 'add_meta_boxes_dx_lead', 'dx_lead_meta_boxes' );

/**
 * Render the CRM box. Every stored value is escaped (standard 15.2.9).
 *
 * @param WP_Post $post Lead.
 */
function dx_lead_crm_box( $post ) {
	$status  = (string) get_post_meta( $post->ID, 'dx_status', true );
	$notes   = (string) get_post_meta( $post->ID, 'dx_notes', true );
	$utm     = (string) get_post_meta( $post->ID, 'dx_utm', true );
	$source  = (string) get_post_meta( $post->ID, 'dx_source', true );
	$consent = json_decode( (string) get_post_meta( $post->ID, 'dx_consent', true ), true );

	wp_nonce_field( 'dx_lead_crm_' . $post->ID, 'dx_lead_crm_nonce' );
	?>
	<p>
		<label for="dx-lead-status"><strong><?php esc_html_e( 'סטטוס', 'diromax' ); ?></strong></label><br>
		<select id="dx-lead-status" name="dx_status">
			<?php foreach ( dx_lead_statuses() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"<?php selected( '' === $status ? 'new' : $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<p>
		<label for="dx-lead-notes"><strong><?php esc_html_e( 'הערות פנימיות', 'diromax' ); ?></strong></label>
		<textarea id="dx-lead-notes" name="dx_notes" rows="5" class="widefat" maxlength="2000"><?php echo esc_textarea( $notes ); ?></textarea>
	</p>
	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: page, 2: campaign. */
				__( 'מקור: %1$s · קמפיין: %2$s', 'diromax' ),
				'' === $source ? '—' : $source,
				'' === $utm ? '—' : $utm
			)
		);
		?>
	</p>
	<?php if ( is_array( $consent ) ) : ?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: date, 2: policy version. */
					__( 'הודעת הפרטיות הוצגה בטופס. נשלח: %1$s · גרסת המדיניות: %2$s', 'diromax' ),
					(string) ( $consent['at'] ?? '' ),
					(string) ( $consent['policy'] ?? '' )
				)
			);
			?>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Save status and notes: nonce, capability, allowlist (standard 4.2, 16.3).
 *
 * @param int $post_id Lead.
 */
function dx_lead_save_crm( $post_id ) {
	if ( ! isset( $_POST['dx_lead_crm_nonce'] ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dx_lead_crm_nonce'] ) ), 'dx_lead_crm_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$status = isset( $_POST['dx_status'] ) ? sanitize_key( wp_unslash( $_POST['dx_status'] ) ) : '';
	$notes  = isset( $_POST['dx_notes'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['dx_notes'] ) ), 0, 2000 ) : '';
	$before = (string) get_post_meta( $post_id, 'dx_status', true );

	if ( isset( dx_lead_statuses()[ $status ] ) && $status !== $before ) {
		update_post_meta( $post_id, 'dx_status', $status );
		dx_lead_audit( 'status', $post_id, $before . ' → ' . $status );
	}

	if ( (string) get_post_meta( $post_id, 'dx_notes', true ) !== $notes ) {
		update_post_meta( $post_id, 'dx_notes', $notes );
		dx_lead_audit( 'notes', $post_id );
	}
}
add_action( 'save_post_dx_lead', 'dx_lead_save_crm' );

/**
 * Status column on the list.
 *
 * @param array<string,string> $columns Columns.
 * @return array<string,string>
 */
function dx_lead_status_column( $columns ) {
	$columns['dx_status'] = __( 'סטטוס', 'diromax' );

	return $columns;
}
add_filter( 'manage_dx_lead_posts_columns', 'dx_lead_status_column', 20 );

/**
 * Status cell on the list.
 *
 * @param string $column  Column.
 * @param int    $post_id Lead.
 */
function dx_lead_status_cell( $column, $post_id ) {
	if ( 'dx_status' === $column ) {
		$status = (string) get_post_meta( $post_id, 'dx_status', true );
		echo esc_html( dx_lead_statuses()[ '' === $status ? 'new' : $status ] ?? '' );
	}
}
add_action( 'manage_dx_lead_posts_custom_column', 'dx_lead_status_cell', 10, 2 );

/* ---------------------------------------------------------------
 * Export (standard 15.2.4)
 * ------------------------------------------------------------ */

/**
 * Defuse a cell a spreadsheet would run as a formula (standard 4.2).
 *
 * @param string $value Cell.
 * @return string
 */
function dx_csv_cell( $value ) {
	$value = (string) $value;

	return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
}

/**
 * Every lead as CSV text, with a UTF-8 BOM for Excel.
 *
 * @return string
 */
function dx_leads_csv() {
	$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory buffer for fputcsv.

	if ( false === $handle ) {
		return '';
	}

	fputcsv( $handle, array( 'תאריך', 'שם', 'טלפון', 'כתובת הנכס', 'סוג הנכס', 'נושא', 'פרטים', 'מקור', 'קמפיין', 'סטטוס', 'הערות' ), ',', '"', '' );

	$leads    = get_posts(
		array(
			'post_type'      => 'dx_lead',
			'post_status'    => 'any',
			'posts_per_page' => 5000,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);
	$statuses = dx_lead_statuses();

	foreach ( $leads as $lead ) {
		$meta   = static fn( $key ) => (string) get_post_meta( $lead->ID, 'dx_' . $key, true );
		$status = $meta( 'status' );

		fputcsv(
			$handle,
			array_map(
				'dx_csv_cell',
				array( get_the_date( 'Y-m-d H:i', $lead ), $meta( 'name' ), $meta( 'phone' ), $meta( 'address' ), $meta( 'type' ), $meta( 'subject' ), $meta( 'message' ), $meta( 'source' ), $meta( 'utm' ), $statuses[ '' === $status ? 'new' : $status ] ?? '', $meta( 'notes' ) )
			),
			',',
			'"',
			''
		);
	}

	rewind( $handle );
	$csv = (string) stream_get_contents( $handle );
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the buffer above.

	return "\xEF\xBB\xBF" . $csv;
}

/**
 * Download every lead (admin-post.php?action=dx_leads_export).
 */
function dx_leads_export() {
	check_admin_referer( 'dx_leads_export' );

	if ( ! dx_can_manage_leads() ) {
		wp_die( esc_html__( 'אין הרשאה.', 'diromax' ), '', array( 'response' => 403 ) );
	}

	$csv = dx_leads_csv();
	dx_lead_audit( 'export', 0, (string) substr_count( $csv, "\n" ) );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="leads-' . gmdate( 'Y-m-d' ) . '.csv"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download; cells defused by dx_csv_cell().
	exit;
}
add_action( 'admin_post_dx_leads_export', 'dx_leads_export' );

/**
 * Export button above the leads list.
 *
 * @param string $which top or bottom.
 */
function dx_leads_export_button( $which ) {
	if ( 'top' !== $which || 'dx_lead' !== get_current_screen()?->post_type || ! dx_can_manage_leads() ) {
		return;
	}

	printf(
		'<a class="button" href="%1$s">%2$s</a>',
		esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dx_leads_export' ), 'dx_leads_export' ) ),
		esc_html__( 'ייצוא ל-CSV', 'diromax' )
	);
}
add_action( 'manage_posts_extra_tablenav', 'dx_leads_export_button' );

/**
 * The audit log, under the leads menu.
 */
function dx_leads_audit_menu() {
	add_submenu_page( 'edit.php?post_type=dx_lead', __( 'יומן לידים', 'diromax' ), __( 'יומן', 'diromax' ), 'edit_others_dx_leads', 'dx-lead-audit', 'dx_leads_audit_page' );
}
add_action( 'admin_menu', 'dx_leads_audit_menu' );

/**
 * Render the audit log.
 */
function dx_leads_audit_page() {
	if ( ! dx_can_manage_leads() ) {
		wp_die( esc_html__( 'אין הרשאה.', 'diromax' ), '', array( 'response' => 403 ) );
	}

	$log    = get_option( 'dx_lead_audit', array() );
	$labels = array(
		'view'      => __( 'צפייה', 'diromax' ),
		'status'    => __( 'שינוי סטטוס', 'diromax' ),
		'notes'     => __( 'עדכון הערות', 'diromax' ),
		'export'    => __( 'ייצוא', 'diromax' ),
		'delete'    => __( 'מחיקה', 'diromax' ),
		'retention' => __( 'מחיקה אוטומטית (תקופת שמירה)', 'diromax' ),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'יומן לידים', 'diromax' ); ?></h1>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: months. */
					__( 'לידים נמחקים אוטומטית %d חודשים אחרי שהתקבלו. אפשר לשנות במסך ההתאמה של דירומקס.', 'diromax' ),
					dx_lead_retention_months()
				)
			);
			?>
		</p>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'מתי (UTC)', 'diromax' ); ?></th>
				<th scope="col"><?php esc_html_e( 'מי', 'diromax' ); ?></th>
				<th scope="col"><?php esc_html_e( 'פעולה', 'diromax' ); ?></th>
				<th scope="col"><?php esc_html_e( 'ליד', 'diromax' ); ?></th>
				<th scope="col"><?php esc_html_e( 'פרטים', 'diromax' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( array_slice( is_array( $log ) ? $log : array(), 0, 300 ) as $entry ) : ?>
					<?php $user = get_userdata( (int) ( $entry['user'] ?? 0 ) ); ?>
					<tr>
						<td dir="ltr"><?php echo esc_html( (string) ( $entry['at'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : __( 'מערכת', 'diromax' ) ); ?></td>
						<td><?php echo esc_html( $labels[ $entry['action'] ?? '' ] ?? (string) ( $entry['action'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( empty( $entry['lead'] ) ? '—' : '#' . (int) $entry['lead'] ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['details'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/* ---------------------------------------------------------------
 * Retention (standard 15.2.5, 5.x)
 * ------------------------------------------------------------ */

/**
 * Months a lead is kept.
 *
 * @return int
 */
function dx_lead_retention_months() {
	$months = (int) get_theme_mod( 'dx_lead_retention', 24 );

	return $months >= 1 && $months <= 120 ? $months : 24;
}

/**
 * Daily: delete leads older than the retention period, in batches.
 *
 * @return int Leads deleted.
 */
function dx_lead_cleanup() {
	$ids = get_posts(
		array(
			'post_type'      => 'dx_lead',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'date_query'     => array(
				array(
					'before' => gmdate( 'Y-m-d H:i:s', (int) strtotime( '-' . dx_lead_retention_months() . ' months' ) ),
					'column' => 'post_date_gmt',
				),
			),
		)
	);

	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}

	if ( $ids ) {
		dx_lead_audit( 'retention', 0, (string) count( $ids ) );
	}

	return count( $ids );
}
add_action( 'dx_lead_cleanup', 'dx_lead_cleanup' );

/**
 * Keep the daily job scheduled.
 */
function dx_lead_schedule() {
	if ( ! wp_next_scheduled( 'dx_lead_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dx_lead_cleanup' );
	}
}
add_action( 'init', 'dx_lead_schedule' );

/* ---------------------------------------------------------------
 * Notification with retry (standard 15.2.6)
 * ------------------------------------------------------------ */

/**
 * Tell the office about a lead. Only what is needed to call back is in the
 * email; the details stay in the dashboard. A failed send is retried.
 *
 * @param int $lead_id Lead.
 * @return bool Sent.
 */
function dx_lead_notify( $lead_id ) {
	$to = get_theme_mod( 'dx_lead_to' );
	$to = is_email( $to ) ? $to : get_option( 'admin_email' );

	$name  = (string) get_post_meta( $lead_id, 'dx_name', true );
	$lines = array(
		__( 'שם', 'diromax' ) . ': ' . $name,
		__( 'טלפון', 'diromax' ) . ': ' . (string) get_post_meta( $lead_id, 'dx_phone', true ),
		__( 'נושא', 'diromax' ) . ': ' . (string) get_post_meta( $lead_id, 'dx_subject', true ),
		'',
		__( 'כל הפרטים בלוח הניהול:', 'diromax' ) . ' ' . admin_url( 'post.php?post=' . (int) $lead_id . '&action=edit' ),
	);

	$sent = wp_mail(
		$to,
		/* translators: %s: lead name. */
		sprintf( __( 'ליד חדש מהאתר — %s', 'diromax' ), $name ),
		implode( "\n", $lines ),
		array( 'Content-Type: text/plain; charset=UTF-8' )
	);

	if ( $sent ) {
		update_post_meta( $lead_id, 'dx_mail', 'sent' );
		return true;
	}

	$tries = (int) get_post_meta( $lead_id, 'dx_mail_tries', true ) + 1;
	update_post_meta( $lead_id, 'dx_mail_tries', $tries );
	update_post_meta( $lead_id, 'dx_mail', 'failed' );

	if ( $tries < DX_LEAD_MAIL_TRIES ) {
		wp_schedule_single_event( time() + $tries * 10 * MINUTE_IN_SECONDS, 'dx_lead_notify_retry', array( (int) $lead_id ) );
	}

	return false;
}
add_action( 'dx_lead_notify_retry', 'dx_lead_notify' );
