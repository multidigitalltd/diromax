<?php
/**
 * Theme helpers that turn values WordPress may return as null or false into
 * plain text before they reach the markup.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

final class ThemeHelpersTest extends PortalTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->assertTrue( function_exists( 'dx_phone_digits' ), 'The theme is loaded in the tests.' );
	}

	public function test_phone_digits_keep_only_what_a_link_may_hold(): void {
		$this->assertSame( '+97254', dx_phone_digits( '+972-54' ) );
		$this->assertSame( '0540000000', dx_phone_digits( '054-000 0000' ) );
		$this->assertSame( '972540000000', dx_phone_digits( '+972 54-000-0000', false ) );
		$this->assertSame( '', dx_phone_digits( 'טלפון' ) );
		$this->assertSame( '', dx_phone_digits( '' ) );
	}

	public function test_the_whatsapp_link_has_digits_only(): void {
		$this->assertSame( 'https://wa.me/972540000000', dx_wa_url() );
	}

	public function test_an_excerpt_that_is_not_valid_utf8_falls_back_to_the_view_text(): void {
		$post = self::factory()->post->create( array( 'post_excerpt' => 'תקציר' ) );
		add_filter( 'get_the_excerpt', static fn(): string => "abc\xff  def" );
		$this->go_to( (string) get_permalink( $post ) );

		$description = dx_meta_description();

		$this->assertNotSame( '', $description );
		$this->assertStringNotContainsString( 'abc', $description );
		remove_all_filters( 'get_the_excerpt' );
	}

	public function test_post_date_is_text_or_empty(): void {
		$post = self::factory()->post->create( array( 'post_date' => '2026-03-04 05:06:07' ) );

		$this->assertSame( '2026-03-04 05:06', dx_post_date( 'Y-m-d H:i', $post ) );
		$this->assertSame( '', dx_post_date( 'Y-m-d', PHP_INT_MAX ), 'No such post.' );
	}

	public function test_adjacent_posts_link_only_to_posts_that_exist(): void {
		$first  = self::factory()->post->create(
			array(
				'post_title' => 'ראשון',
				'post_date'  => '2026-01-01 10:00:00',
			)
		);
		$middle = self::factory()->post->create(
			array(
				'post_title' => 'אמצעי',
				'post_date'  => '2026-01-02 10:00:00',
			)
		);
		$last   = self::factory()->post->create(
			array(
				'post_title' => 'אחרון',
				'post_date'  => '2026-01-03 10:00:00',
			)
		);

		$this->go_to( (string) get_permalink( $middle ) );
		$links = dx_adjacent_posts();

		$this->assertSame( array( 'general.post_prev', 'general.post_next' ), array_column( $links, 'label' ) );
		$this->assertSame( array( 'ראשון', 'אחרון' ), array_column( $links, 'title' ) );
		$this->assertSame( array( get_permalink( $first ), get_permalink( $last ) ), array_column( $links, 'url' ) );

		$this->go_to( (string) get_permalink( $first ) );
		$this->assertSame( array( 'general.post_next' ), array_column( dx_adjacent_posts(), 'label' ), 'The oldest post has no previous one.' );
	}

	public function test_a_term_archive_points_at_the_term(): void {
		$category = self::factory()->category->create( array( 'slug' => 'renovation' ) );
		self::factory()->post->create( array( 'post_category' => array( $category ) ) );

		$this->go_to( (string) get_term_link( $category ) );

		$this->assertSame( get_term_link( $category ), dx_current_url() );
	}
}
