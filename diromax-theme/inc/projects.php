<?php
/**
 * "Before / after" projects. Two images plus a caption per project.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the projects post type.
 */
function dx_register_project_cpt() {
	register_post_type(
		'dx_project',
		array(
			'labels'       => array(
				'name'          => __( 'פרויקטים', 'diromax' ),
				'singular_name' => __( 'פרויקט', 'diromax' ),
				'add_new_item'  => __( 'פרויקט חדש', 'diromax' ),
				'edit_item'     => __( 'עריכת פרויקט', 'diromax' ),
			),
			'public'       => true,
			'has_archive'  => false,
			'menu_icon'    => 'dashicons-building',
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'rewrite'      => array( 'slug' => 'projects' ),
			'show_in_rest' => true,
		)
	);
}
add_action( 'init', 'dx_register_project_cpt' );

/**
 * Meta box for the two image IDs.
 */
function dx_project_meta_box() {
	add_meta_box(
		'dx_project_images',
		__( 'תמונות לפני / אחרי', 'diromax' ),
		'dx_project_meta_box_html',
		'dx_project',
		'side'
	);
}
add_action( 'add_meta_boxes', 'dx_project_meta_box' );

/**
 * Meta box markup.
 *
 * @param WP_Post $post Current project.
 */
function dx_project_meta_box_html( $post ) {
	wp_nonce_field( 'dx_project_meta', 'dx_project_nonce' );

	$before = (int) get_post_meta( $post->ID, 'dx_before_id', true );
	$after  = (int) get_post_meta( $post->ID, 'dx_after_id', true );
	?>
	<p>
		<label for="dx_before_id"><?php esc_html_e( 'מזהה תמונת "לפני" (Media ID)', 'diromax' ); ?></label>
		<input type="number" min="0" id="dx_before_id" name="dx_before_id" value="<?php echo esc_attr( (string) $before ); ?>" class="widefat">
	</p>
	<p>
		<label for="dx_after_id"><?php esc_html_e( 'מזהה תמונת "אחרי" (Media ID)', 'diromax' ); ?></label>
		<input type="number" min="0" id="dx_after_id" name="dx_after_id" value="<?php echo esc_attr( (string) $after ); ?>" class="widefat">
	</p>
	<p class="description"><?php esc_html_e( 'התמונה הראשית (Featured) משמשת כברירת מחדל ל"אחרי".', 'diromax' ); ?></p>
	<?php
}

/**
 * Persist the meta box values.
 *
 * @param int $post_id Project ID.
 */
function dx_project_save( $post_id ) {
	if ( ! isset( $_POST['dx_project_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dx_project_nonce'] ) ), 'dx_project_meta' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	foreach ( array( 'dx_before_id', 'dx_after_id' ) as $key ) {
		update_post_meta( $post_id, $key, absint( $_POST[ $key ] ?? 0 ) );
	}
}
add_action( 'save_post_dx_project', 'dx_project_save' );

/**
 * Render one before/after card, or the empty placeholder pair from the design.
 *
 * @param WP_Post|null $post Project, or null for the placeholder.
 */
function dx_project_card( $post = null ) {
	$title     = (string) dx_content( 'improve.project_title' );
	$desc      = (string) dx_content( 'improve.project_desc' );
	$before_id = 0;
	$after_id  = 0;

	if ( $post ) {
		$title     = get_the_title( $post );
		$desc      = get_the_excerpt( $post );
		$before_id = (int) get_post_meta( $post->ID, 'dx_before_id', true );
		$after_id  = (int) get_post_meta( $post->ID, 'dx_after_id', true );

		if ( ! $after_id ) {
			$after_id = (int) get_post_thumbnail_id( $post );
		}
	}
	?>
	<li class="dx-project">
		<div class="dx-project__imgs">
			<div class="dx-project__img dx-project__img--before">
				<?php if ( $before_id ) : ?>
					<?php
					echo wp_get_attachment_image(
						$before_id,
						'dx-square',
						false,
						array(
							/* translators: %s: project title. */
							'alt'     => sprintf( __( '%s — לפני ההשבחה', 'diromax' ), $title ),
							'loading' => 'lazy',
						)
					);
					?>
				<?php else : ?>
					<span class="dx-project__tag"><?php esc_html_e( 'לפני', 'diromax' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="dx-project__img">
				<?php if ( $after_id ) : ?>
					<?php
					echo wp_get_attachment_image(
						$after_id,
						'dx-square',
						false,
						array(
							/* translators: %s: project title. */
							'alt'     => sprintf( __( '%s — אחרי ההשבחה', 'diromax' ), $title ),
							'loading' => 'lazy',
						)
					);
					?>
				<?php else : ?>
					<span class="dx-project__tag"><?php esc_html_e( 'אחרי', 'diromax' ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<div class="dx-project__body">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo esc_html( $desc ); ?></p>
		</div>
	</li>
	<?php
}
