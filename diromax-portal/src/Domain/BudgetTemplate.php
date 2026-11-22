<?php
/**
 * The default budget, as text.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The office's default budget (spec 19.2) is edited in one textarea on the
 * settings screen: a section on each line, its sub-sections under it on
 * lines that start with a dash.
 *
 *     יועצים ומדידות
 *     - קונסטרוקציה
 *     - מדידות
 *     אגרות והיטלים
 *
 * Keys stay out of sight: a line keeps the key it had (matched by its label
 * and section), and a new line gets one made from its label. "key=Label"
 * still works for whoever wants to set a key.
 *
 * Pure: no WordPress calls, so the parser has unit tests.
 */
final class BudgetTemplate {

	/** Characters that mark a sub-section line. */
	private const MARKERS = array( '-', '–', '—', '*', '•' );

	/** Longest label in the default. */
	private const MAX_LABEL = 60;

	/** Key that means "no section" in the ledger. */
	private const RESERVED = 'other';

	/**
	 * Parse the textarea.
	 *
	 * @param string     $text    Textarea value.
	 * @param BudgetTree $current The default as saved, for keeping keys.
	 * @return BudgetTree Planned amounts are zero.
	 */
	public static function parse( string $text, BudgetTree $current ): BudgetTree {
		$lines   = array();
		$used    = array();
		$section = '';
		$count   = 0;

		$rows = preg_split( '/\R/u', $text );

		foreach ( false === $rows ? array() : $rows as $raw ) {
			$raw   = trim( $raw );
			$child = '' !== $raw && in_array( mb_substr( $raw, 0, 1 ), self::MARKERS, true );
			$raw   = $child ? trim( mb_substr( $raw, 1 ) ) : $raw;
			$key   = '';

			if ( 1 === preg_match( '/^([a-z0-9_-]{1,40})\s*=\s*(.+)$/u', $raw, $match ) ) {
				$key = self::RESERVED === $match[1] ? '' : $match[1];
				$raw = $match[2];
			}

			$label = mb_substr( Text::line( $raw ), 0, self::MAX_LABEL );

			if ( '' === $label || ( $child && '' === $section ) ) {
				continue;
			}

			$parent = $child ? $section : '';
			$key    = self::unique( '' !== $key ? $key : self::known_key( $current, $parent, $label ), $parent, $label, $used );

			$used[ $key ] = true;
			$lines[]      = array(
				'key'     => $key,
				'parent'  => $parent,
				'label'   => $label,
				'planned' => 0,
			);

			if ( ! $child ) {
				$section = $key;
			}

			if ( ++$count >= BudgetTree::MAX_SECTIONS * 4 ) {
				break;
			}
		}

		return new BudgetTree( $lines );
	}

	/**
	 * The default as textarea text.
	 *
	 * @param BudgetTree $tree Default.
	 * @return string
	 */
	public static function text( BudgetTree $tree ): string {
		return implode(
			"\n",
			array_map( static fn( array $line ): string => ( '' === $line['parent'] ? '' : '- ' ) . $line['label'], $tree->lines() )
		);
	}

	/**
	 * The key a line had: same label under the same section.
	 *
	 * @param BudgetTree $current Saved default.
	 * @param string     $section Section key, '' for a section.
	 * @param string     $label   Label.
	 * @return string '' when it is new.
	 */
	private static function known_key( BudgetTree $current, string $section, string $label ): string {
		$siblings = '' === $section ? $current->sections() : $current->children( $section );

		foreach ( $siblings as $line ) {
			if ( mb_strtolower( $line['label'] ) === mb_strtolower( $label ) ) {
				return $line['key'];
			}
		}

		return '';
	}

	/**
	 * A key not taken yet; one made from the label when none is given.
	 *
	 * @param string              $key     Wanted key, or ''.
	 * @param string              $section Section key.
	 * @param string              $label   Label.
	 * @param array<string, true> $used    Keys taken.
	 * @return string
	 */
	private static function unique( string $key, string $section, string $label, array $used ): string {
		$base = '' !== $key ? $key : 'b' . substr( hash( 'sha256', $section . '|' . mb_strtolower( $label ) ), 0, 8 );
		$key  = $base;

		for ( $n = 2; isset( $used[ $key ] ); $n++ ) {
			$key = substr( $base, 0, 36 ) . '-' . $n;
		}

		return $key;
	}
}
