<?php
/**
 * Quote comparisons.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The rules of a quote comparison (spec 24): a job, the budget line it
 * belongs to, and the quotes for it. It goes to the client to choose once it
 * holds at least two quotes (decision 43); the client's choice is recorded
 * once (decision 44); the manager then records it as a commitment
 * (decision 45).
 *
 * Pure: no WordPress, no database. Amounts in agorot.
 *
 * @phpstan-type ComparisonFields array{title:string, category:string, note:string, due_on:string|null}
 * @phpstan-type OfferFields array{supplier:string, amount:int, duration:string, note:string, recommended:bool}
 */
final class QuoteComparison {

	/** Fewest quotes to send. */
	public const MIN_OFFERS = 2;

	/** Most quotes in one comparison. */
	public const MAX_OFFERS = 6;

	/** Longest texts, in characters. */
	private const MAX_TITLE    = 160;
	private const MAX_NOTE     = 2000;
	private const MAX_SUPPLIER = 120;
	private const MAX_DURATION = 60;
	public const MAX_SHORT     = 500;

	/**
	 * Check a comparison: what job, which budget line, a note, by when.
	 *
	 * @param array<string, string> $input      title, category, note, due_on.
	 * @param string[]              $categories The case's budget lines.
	 * @return array{fields:ComparisonFields|null, errors:array<string, string>}
	 */
	public static function comparison( array $input, array $categories ): array {
		$errors   = array();
		$title    = Text::line( $input['title'] ?? '' );
		$category = (string) ( $input['category'] ?? '' );
		$note     = Text::block( $input['note'] ?? '' );
		$due      = trim( $input['due_on'] ?? '' );

		if ( '' === $title ) {
			$errors['title'] = 'required';
		} elseif ( Text::length( $title ) > self::MAX_TITLE ) {
			$errors['title'] = 'too_long';
		}

		if ( '' !== $category && ! in_array( $category, $categories, true ) ) {
			$errors['category'] = 'invalid';
		}

		if ( Text::length( $note ) > self::MAX_NOTE ) {
			$errors['note'] = 'too_long';
		}

		if ( '' !== $due && ! self::is_date( $due ) ) {
			$errors['due_on'] = 'date';
		}

		if ( array() !== $errors ) {
			return array(
				'fields' => null,
				'errors' => $errors,
			);
		}

		return array(
			'fields' => array(
				'title'    => $title,
				'category' => $category,
				'note'     => $note,
				'due_on'   => '' === $due ? null : $due,
			),
			'errors' => array(),
		);
	}

	/**
	 * Check a quote: who, how much, how long, a note.
	 *
	 * @param array<string, string> $input supplier, amount (shekels), duration, note, recommended.
	 * @return array{fields:OfferFields|null, errors:array<string, string>}
	 */
	public static function offer( array $input ): array {
		$errors   = array();
		$supplier = Text::line( $input['supplier'] ?? '' );
		$amount   = Money::parse( (string) ( $input['amount'] ?? '' ) );
		$duration = Text::line( $input['duration'] ?? '' );
		$note     = Text::line( $input['note'] ?? '' );

		if ( '' === $supplier ) {
			$errors['supplier'] = 'required';
		} elseif ( Text::length( $supplier ) > self::MAX_SUPPLIER ) {
			$errors['supplier'] = 'too_long';
		}

		if ( null === $amount || $amount <= 0 || $amount > Money::MAX ) {
			$errors['amount'] = 'invalid';
		}

		if ( Text::length( $duration ) > self::MAX_DURATION ) {
			$errors['duration'] = 'too_long';
		}

		if ( Text::length( $note ) > self::MAX_SHORT ) {
			$errors['note'] = 'too_long';
		}

		if ( array() !== $errors || null === $amount ) {
			return array(
				'fields' => null,
				'errors' => $errors,
			);
		}

		return array(
			'fields' => array(
				'supplier'    => $supplier,
				'amount'      => $amount,
				'duration'    => $duration,
				'note'        => $note,
				'recommended' => '1' === ( $input['recommended'] ?? '' ),
			),
			'errors' => array(),
		);
	}

	/**
	 * The lowest and highest quote, and the gap between them.
	 *
	 * @param array<int, array{id:int, amount:int}> $offers Quotes.
	 * @return array{lowest:int|null, highest:int|null, gap:int} IDs of the lowest and highest; gap in agorot.
	 */
	public static function range( array $offers ): array {
		if ( array() === $offers ) {
			return array(
				'lowest'  => null,
				'highest' => null,
				'gap'     => 0,
			);
		}

		usort( $offers, static fn( array $a, array $b ): int => array( $a['amount'], $a['id'] ) <=> array( $b['amount'], $b['id'] ) );
		$low  = $offers[0];
		$high = $offers[ count( $offers ) - 1 ];

		return array(
			'lowest'  => $low['id'],
			'highest' => $high['id'],
			'gap'     => $high['amount'] - $low['amount'],
		);
	}

	/**
	 * A real Y-m-d date.
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	private static function is_date( string $date ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}
}
