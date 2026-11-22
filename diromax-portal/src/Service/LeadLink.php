<?php
/**
 * Opening a case from a lead of the Diromax theme.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Plugin;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Bridge to the theme's lead post type (spec 15.9). Everything here is
 * optional: without the theme the post type does not exist and nothing shows.
 */
final class LeadLink {

	/** The theme's lead post type. */
	public const POST_TYPE = 'dx_lead';

	/** Lead meta holding the case ID. */
	public const META = 'dx_case_id';

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * A lead the user may open a case from: it exists, they may edit it, and
	 * no case was opened from it yet.
	 *
	 * @param int $lead_id Lead post ID.
	 * @param int $user    User.
	 * @return WP_Post|null
	 */
	public function usable( int $lead_id, int $user ): ?WP_Post {
		if ( $lead_id <= 0 || ! post_type_exists( self::POST_TYPE ) || ! $this->app->policy->can_use_admin( $user ) ) {
			return null;
		}

		$post = get_post( $lead_id );

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || ! user_can( $user, 'edit_post', $lead_id ) ) {
			return null;
		}

		return '' === (string) get_post_meta( $lead_id, self::META, true ) ? $post : null;
	}

	/**
	 * Values for the new-case form, read from the lead.
	 *
	 * @param int $lead_id Lead.
	 * @param int $user    User.
	 * @return array{lead_id:int, title:string, property_type:string, name:string, phone:string}|null
	 */
	public function prefill( int $lead_id, int $user ): ?array {
		$lead = $this->usable( $lead_id, $user );

		if ( null === $lead ) {
			return null;
		}

		$meta = static fn( string $key ): string => (string) get_post_meta( $lead->ID, 'dx_' . $key, true );

		return array(
			'lead_id'       => $lead->ID,
			'title'         => $meta( 'address' ),
			'property_type' => $meta( 'type' ),
			'name'          => $meta( 'name' ),
			'phone'         => $meta( 'phone' ),
		);
	}

	/**
	 * Record on the lead which case it became.
	 *
	 * @param int        $lead_id     Lead.
	 * @param CaseRecord $client_case Case.
	 */
	public function link( int $lead_id, CaseRecord $client_case ): void {
		update_post_meta( $lead_id, self::META, $client_case->id );
	}

	/**
	 * Forget the case a lead was opened into (the case was deleted), so a
	 * new case can be opened from it.
	 *
	 * @param int $lead_id Lead post.
	 * @param int $case_id The deleted case.
	 */
	public function unlink( int $lead_id, int $case_id ): void {
		delete_post_meta( $lead_id, self::META, (string) $case_id );
	}

	/**
	 * Row actions on the leads list: open a case, or go to the case opened.
	 *
	 * @param array<string, string> $actions Actions.
	 * @param WP_Post               $post    Row post.
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, WP_Post $post ): array {
		$user = get_current_user_id();

		if ( self::POST_TYPE !== $post->post_type || ! $this->app->policy->can_use_admin( $user ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$case_id = (int) get_post_meta( $post->ID, self::META, true );

		if ( $case_id > 0 ) {
			$client_case = $this->app->cases->find( $case_id );

			if ( null !== $client_case && $this->app->policy->can_manage_case( $user, $client_case ) ) {
				/* translators: %s: case number. */
				$actions['dx_case'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Admin::case_url( $client_case->id ) ), esc_html( sprintf( __( 'תיק %s', 'diromax-portal' ), $client_case->number ) ) );
			}

			return $actions;
		}

		$url = add_query_arg(
			array(
				'page' => Admin::PAGE_NEW,
				'lead' => $post->ID,
			),
			admin_url( 'admin.php' )
		);

		$actions['dx_open_case'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'פתיחת תיק', 'diromax-portal' ) );

		return $actions;
	}
}
