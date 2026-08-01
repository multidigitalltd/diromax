<?php
/**
 * Content installer.
 *
 * Creates the site the design describes: the five views with their templates,
 * the accessibility statement, the legal pages and the six articles — all with
 * their real text, not placeholders.
 *
 * Admin only, capability checked, nonce protected, and idempotent: running it
 * twice never duplicates anything.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The five designed views: page title => template file.
 *
 * @return array<string,array{title:string,template:string,view:string}>
 */
function dx_seed_pages() {
	return array(
		'improve'  => array(
			'title'    => 'ניהול השבחת נכסים',
			'template' => 'page-templates/template-improvement.php',
		),
		'broker'   => array(
			'title'    => 'תיווך',
			'template' => 'page-templates/template-broker.php',
		),
		'faq'      => array(
			'title'    => 'שאלות ותשובות',
			'template' => 'page-templates/template-faq.php',
		),
		'articles' => array(
			'title'    => 'מאמרים',
			'template' => 'page-templates/template-articles.php',
		),
		'contact'  => array(
			'title'    => 'צור קשר',
			'template' => 'page-templates/template-contact.php',
		),
		'a11y'     => array(
			'title'    => 'הצהרת נגישות',
			'template' => 'page-templates/template-accessibility.php',
		),
	);
}

/**
 * Turn the compact block description into editor-ready block markup.
 *
 * @param array<int,array{0:string,1:mixed}> $blocks Block list.
 * @return string
 */
function dx_blocks_to_html( array $blocks ) {
	$out = array();

	foreach ( $blocks as $block ) {
		$tag  = $block[0];
		$body = $block[1];

		switch ( $tag ) {
			case 'h2':
				$out[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $body ) . "</h2>\n<!-- /wp:heading -->";
				break;

			case 'h3':
				$out[] = "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . esc_html( $body ) . "</h3>\n<!-- /wp:heading -->";
				break;

			case 'ul':
				$items = '';
				foreach ( (array) $body as $item ) {
					$items .= "<!-- wp:list-item -->\n<li>" . esc_html( $item ) . "</li>\n<!-- /wp:list-item -->\n";
				}
				$out[] = "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n" . $items . "</ul>\n<!-- /wp:list -->";
				break;

			case 'note':
				$out[] = "<!-- wp:paragraph {\"className\":\"dx-post__note\"} -->\n<p class=\"dx-post__note\">" . esc_html( $body ) . "</p>\n<!-- /wp:paragraph -->";
				break;

			default:
				$out[] = "<!-- wp:paragraph -->\n<p>" . esc_html( $body ) . "</p>\n<!-- /wp:paragraph -->";
				break;
		}
	}

	return implode( "\n\n", $out );
}

/**
 * Find a published page already using a given theme template.
 *
 * @param string $template Template path.
 * @return int Page ID, or 0.
 */
function dx_find_page_by_template( $template ) {
	$found = get_posts(
		array(
			'post_type'              => 'page',
			'post_status'            => array( 'publish', 'draft', 'pending' ),
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_key'               => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'             => $template, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	return $found ? (int) $found[0] : 0;
}

/**
 * Move WordPress's untouched sample content to the trash.
 *
 * Only ever touches the two default items, and only while they still carry
 * their original slug and unedited content — anything a person has written is
 * left alone.
 *
 * @return int Number of items trashed.
 */
function dx_trash_sample_content() {
	$trashed = 0;

	$defaults = array(
		array( 'hello-world', 'post' ),
		array( 'sample-page', 'page' ),
	);

	foreach ( $defaults as $default ) {
		list( $slug, $type ) = $default;

		$post = get_page_by_path( $slug, OBJECT, $type );

		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
			continue;
		}

		// Untouched means never revised since creation.
		if ( $post->post_modified_gmt !== $post->post_date_gmt ) {
			continue;
		}

		if ( wp_trash_post( $post->ID ) ) {
			++$trashed;
		}
	}

	return $trashed;
}

/**
 * Run the installation.
 *
 * @param bool $trash_samples Whether to trash WordPress's default sample content.
 * @return array<string,int> Counters describing what was created.
 */
function dx_install_content( $trash_samples = false ) {
	require_once DX_DIR . '/inc/content-articles.php';

	$made = array(
		'pages'    => 0,
		'articles' => 0,
		'legal'    => 0,
		'menu'     => 0,
		'trashed'  => 0,
	);

	if ( $trash_samples ) {
		$made['trashed'] = dx_trash_sample_content();
	}

	/* ---- The five designed views + the accessibility statement ---- */
	$ids = array();

	foreach ( dx_seed_pages() as $key => $page ) {
		$existing = dx_find_page_by_template( $page['template'] );

		if ( $existing ) {
			$ids[ $key ] = $existing;
			continue;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $page['title'],
				'post_content' => '',
				'meta_input'   => array( '_wp_page_template' => $page['template'] ),
			),
			true
		);

		if ( ! is_wp_error( $id ) ) {
			$ids[ $key ] = (int) $id;
			++$made['pages'];
		}
	}

	/* ---- Reading settings: front page and posts page ---- */
	if ( ! empty( $ids['improve'] ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $ids['improve'] );
	}
	if ( ! empty( $ids['articles'] ) ) {
		// The articles template runs its own loop, so the posts page stays unset
		// to avoid two pages competing for the blog archive.
		update_option( 'page_for_posts', 0 );
	}

	/* ---- Legal pages ---- */
	$legal_ids = array();

	foreach ( dx_seed_legal_pages() as $page ) {
		$content  = dx_blocks_to_html( $page['blocks'] );
		$existing = get_page_by_path( $page['slug'] );

		/*
		 * WordPress creates its own privacy-policy page as a draft on install.
		 * Filling that one keeps the core "privacy page" setting pointing at a
		 * real, published policy instead of leaving an empty draft behind.
		 */
		if ( ! empty( $page['privacy'] ) ) {
			$core_id = (int) get_option( 'wp_page_for_privacy_policy' );

			if ( $core_id && get_post_status( $core_id ) ) {
				$existing = get_post( $core_id );
			}
		}

		if ( $existing instanceof WP_Post ) {
			/*
			 * Fill a page that is still empty, or one that only holds the
			 * suggested policy WordPress drafts on install — that draft is
			 * boilerplate nobody wrote. A page someone published is never
			 * touched.
			 */
			$is_core_draft = ( ! empty( $page['privacy'] ) && in_array( $existing->post_status, array( 'draft', 'auto-draft' ), true ) );

			if ( '' === trim( (string) $existing->post_content ) || $is_core_draft ) {
				wp_update_post(
					array(
						'ID'           => $existing->ID,
						'post_title'   => $page['title'],
						'post_status'  => 'publish',
						'post_content' => $content,
					)
				);
				++$made['legal'];
			}

			$legal_ids[] = (int) $existing->ID;
			continue;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $page['title'],
				'post_name'    => $page['slug'],
				'post_content' => $content,
			),
			true
		);

		if ( ! is_wp_error( $id ) ) {
			$legal_ids[] = (int) $id;
			++$made['legal'];

			if ( ! empty( $page['privacy'] ) ) {
				update_option( 'wp_page_for_privacy_policy', (int) $id );
			}
		}
	}

	/* ---- Articles ---- */
	foreach ( dx_seed_articles() as $article ) {
		if ( get_page_by_path( $article['slug'], OBJECT, 'post' ) ) {
			continue;
		}

		$term = term_exists( $article['cat'], 'category' );
		if ( ! $term ) {
			$term = wp_insert_term( $article['cat'], 'category' );
		}
		$term_id = ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) ? (int) $term['term_id'] : 0;

		$id = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => $article['title'],
				'post_name'     => $article['slug'],
				'post_excerpt'  => $article['excerpt'],
				'post_content'  => dx_blocks_to_html( $article['blocks'] ),
				'post_category' => $term_id ? array( $term_id ) : array(),
			),
			true
		);

		if ( ! is_wp_error( $id ) ) {
			++$made['articles'];
		}
	}

	/* ---- Footer menu with the legal and accessibility pages ---- */
	if ( ! has_nav_menu( 'footer' ) && $legal_ids ) {
		$menu_name = __( 'פוטר — מידע', 'diromax' );
		$menu      = wp_get_nav_menu_object( $menu_name );
		$menu_id   = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( $menu_name );

		if ( $menu_id && ! is_wp_error( $menu_id ) ) {
			$targets = $legal_ids;

			if ( ! empty( $ids['a11y'] ) ) {
				$targets[] = (int) $ids['a11y'];
			}

			foreach ( $targets as $target ) {
				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'     => get_the_title( $target ),
						'menu-item-object'    => 'page',
						'menu-item-object-id' => $target,
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
					)
				);
			}

			$locations           = get_theme_mod( 'nav_menu_locations', array() );
			$locations['footer'] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
			++$made['menu'];
		}
	}

	dx_flush_view_urls();
	dx_flush_a11y_url();
	flush_rewrite_rules( false );

	return $made;
}

/**
 * Admin screen under Appearance.
 */
function dx_setup_menu() {
	add_theme_page(
		__( 'התקנת תוכן דירומקס', 'diromax' ),
		__( 'התקנת תוכן דירומקס', 'diromax' ),
		'manage_options',
		'dx-setup',
		'dx_setup_screen'
	);
}
add_action( 'admin_menu', 'dx_setup_menu' );

/**
 * Render the installer screen.
 */
function dx_setup_screen() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'אין לך הרשאה לגשת לעמוד הזה.', 'diromax' ) );
	}

	$installed = isset( $_GET['dx_installed'] ) ? absint( $_GET['dx_installed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'התקנת תוכן דירומקס', 'diromax' ); ?></h1>

		<?php if ( $installed ) : ?>
			<div class="notice notice-success">
				<p><?php esc_html_e( 'התוכן הותקן. אפשר לעבור לאתר ולבדוק.', 'diromax' ); ?></p>
			</div>
		<?php endif; ?>

		<p><?php esc_html_e( 'הפעולה יוצרת את כל מבנה האתר עם התוכן האמיתי שלו:', 'diromax' ); ?></p>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'חמישה עמודים עם התבניות המתאימות: ניהול השבחת נכסים, תיווך, שאלות ותשובות, מאמרים, צור קשר.', 'diromax' ); ?></li>
			<li><?php esc_html_e( 'עמוד הצהרת נגישות, מדיניות פרטיות ותנאי שימוש.', 'diromax' ); ?></li>
			<li><?php esc_html_e( 'שישה מאמרים מלאים עם קטגוריות ותקצירים.', 'diromax' ); ?></li>
			<li><?php esc_html_e( 'הגדרת עמוד הבית ותפריט פוטר.', 'diromax' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'הפעולה בטוחה להרצה חוזרת: מה שכבר קיים לא ייווצר פעם שנייה ולא יידרס.', 'diromax' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dx_install_content">
			<?php wp_nonce_field( 'dx_install_content' ); ?>

			<p>
				<label for="dx_trash_samples">
					<input type="checkbox" id="dx_trash_samples" name="dx_trash_samples" value="1">
					<?php esc_html_e( 'להעביר לסל המיחזור גם את תוכן הדוגמה של וורדפרס ("Hello world!" ו-"Sample Page")', 'diromax' ); ?>
				</label>
				<br>
				<span class="description"><?php esc_html_e( 'רק אם הם עדיין במצבם המקורי ולא נערכו. אפשר לשחזר אותם מסל המיחזור בכל רגע.', 'diromax' ); ?></span>
			</p>

			<?php submit_button( __( 'התקנת התוכן', 'diromax' ) ); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'מה נשאר להשלים ידנית', 'diromax' ); ?></h2>
		<ol>
			<li><?php esc_html_e( 'פרטי קשר אמיתיים: עיצוב ← התאמה אישית ← דירומקס — פרטי קשר.', 'diromax' ); ?></li>
			<li><?php esc_html_e( 'פרטי רכז/ת הנגישות ותאריך ההצהרה: דירומקס — נגישות.', 'diromax' ); ?></li>
			<li><?php esc_html_e( 'תמונת הירו ותמונות לפני/אחרי לפרויקטים.', 'diromax' ); ?></li>
			<li><?php esc_html_e( 'קישורים ידידותיים: הגדרות ← קישורים ידידותיים ← שם הפוסט.', 'diromax' ); ?></li>
		</ol>
	</div>
	<?php
}

/**
 * Handle the installer submission.
 */
function dx_setup_handle() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'אין לך הרשאה לבצע את הפעולה הזו.', 'diromax' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'dx_install_content' );

	dx_install_content( ! empty( $_POST['dx_trash_samples'] ) );

	wp_safe_redirect( add_query_arg( 'dx_installed', 1, admin_url( 'themes.php?page=dx-setup' ) ) );
	exit;
}
add_action( 'admin_post_dx_install_content', 'dx_setup_handle' );

/**
 * Point a fresh install at the installer once, right after activation.
 */
function dx_setup_notice() {
	$screen = get_current_screen();

	if ( ! $screen || 'appearance_page_dx-setup' === $screen->id || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( dx_find_page_by_template( 'page-templates/template-improvement.php' ) ) {
		return;
	}
	?>
	<div class="notice notice-info">
		<p>
			<?php esc_html_e( 'ערכת דירומקס מותקנת. נותר להתקין את תוכן העמודים.', 'diromax' ); ?>
			<a href="<?php echo esc_url( admin_url( 'themes.php?page=dx-setup' ) ); ?>">
				<?php esc_html_e( 'למסך ההתקנה', 'diromax' ); ?>
			</a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'dx_setup_notice' );
