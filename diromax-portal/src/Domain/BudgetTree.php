<?php
/**
 * A case's budget: sections and sub-sections.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The budget of one case as a two-level tree (spec 19.2): sections, each
 * with optional sub-sections. Every line has a key that is unique in the
 * case, a label and a planned amount in agorot. A section's own amount is
 * its "general" part; its total adds its sub-sections.
 *
 * A ledger entry is charged to one key, a section or a sub-section, so the
 * section of an entry is always known from the tree.
 *
 * Pure: no WordPress calls, no database. Edits from the budget form are
 * validated and applied here (edit()), so the rules have unit tests.
 *
 * @phpstan-type Line array{key:string, parent:string, label:string, planned:int}
 */
final class BudgetTree {

	/** Most sections in a case. */
	public const MAX_SECTIONS = 40;

	/** Most sub-sections in a section. */
	public const MAX_CHILDREN = 30;

	/** Longest label. */
	public const MAX_LABEL = 80;

	/** Separator between a section and a sub-section in a full label. */
	public const SEPARATOR = ' › ';

	/** Field of the "new section" row in the form. */
	public const NEW_SECTION = '*';

	/**
	 * Lines in display order: each section, then its sub-sections.
	 *
	 * @var Line[]
	 */
	private readonly array $lines;

	/**
	 * Constructor. Lines are put in tree order; a sub-section whose section
	 * is missing, and a repeated key, are dropped.
	 *
	 * @param Line[] $lines Lines, sections before or after their sub-sections.
	 */
	public function __construct( array $lines ) {
		$sections = array();
		$children = array();
		$seen     = array();

		foreach ( $lines as $line ) {
			if ( '' === $line['key'] || isset( $seen[ $line['key'] ] ) ) {
				continue;
			}

			$seen[ $line['key'] ] = true;

			if ( '' === $line['parent'] ) {
				$sections[] = $line;
			} else {
				$children[ $line['parent'] ][] = $line;
			}
		}

		$ordered = array();

		foreach ( $sections as $section ) {
			$ordered[] = $section;

			foreach ( $children[ $section['key'] ] ?? array() as $child ) {
				$ordered[] = $child;
			}
		}

		$this->lines = $ordered;
	}

	/**
	 * Sections only, from key => label pairs (old budgets, tests).
	 *
	 * @param array<string, int>    $planned Key => agorot.
	 * @param array<string, string> $labels  Key => label; the key when missing.
	 * @return self
	 */
	public static function flat( array $planned, array $labels = array() ): self {
		$lines = array();

		foreach ( $planned as $key => $amount ) {
			$lines[] = array(
				'key'     => (string) $key,
				'parent'  => '',
				'label'   => $labels[ $key ] ?? (string) $key,
				'planned' => $amount,
			);
		}

		return new self( $lines );
	}

	/**
	 * Every line, in display order.
	 *
	 * @return Line[]
	 */
	public function lines(): array {
		return $this->lines;
	}

	/**
	 * Is there no line at all?
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->lines;
	}

	/**
	 * One line.
	 *
	 * @param string $key Key.
	 * @return Line|null
	 */
	public function find( string $key ): ?array {
		foreach ( $this->lines as $line ) {
			if ( $line['key'] === $key ) {
				return $line;
			}
		}

		return null;
	}

	/**
	 * Sections, in order.
	 *
	 * @return Line[]
	 */
	public function sections(): array {
		return array_values( array_filter( $this->lines, static fn( array $line ): bool => '' === $line['parent'] ) );
	}

	/**
	 * Sub-sections of a section, in order.
	 *
	 * @param string $section Section key.
	 * @return Line[]
	 */
	public function children( string $section ): array {
		return array_values( array_filter( $this->lines, static fn( array $line ): bool => $section === $line['parent'] ) );
	}

	/**
	 * The section a key belongs to: itself for a section, its parent for a
	 * sub-section, '' when the key is not in the tree.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public function section_of( string $key ): string {
		$line = $this->find( $key );

		if ( null === $line ) {
			return '';
		}

		return '' === $line['parent'] ? $line['key'] : $line['parent'];
	}

	/**
	 * Full label: "Section › Sub-section", or the section's label.
	 *
	 * @param string $key Key.
	 * @return string '' when the key is not in the tree.
	 */
	public function label( string $key ): string {
		$line = $this->find( $key );

		if ( null === $line ) {
			return '';
		}

		if ( '' === $line['parent'] ) {
			return $line['label'];
		}

		$section = $this->find( $line['parent'] );

		return ( null === $section ? '' : $section['label'] . self::SEPARATOR ) . $line['label'];
	}

	/**
	 * Every key, in order (the allowlist for ledger entries).
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_column( $this->lines, 'key' );
	}

	/**
	 * Planned amount of every line: own amounts, not rolled up.
	 *
	 * @return array<string, int>
	 */
	public function planned(): array {
		return array_column( $this->lines, 'planned', 'key' );
	}

	/**
	 * Total planned budget.
	 *
	 * @return int Agorot.
	 */
	public function total(): int {
		return array_sum( array_column( $this->lines, 'planned' ) );
	}

	/**
	 * A section's total: its own amount and its sub-sections'.
	 *
	 * @param string $section Section key.
	 * @return int Agorot.
	 */
	public function section_total( string $section ): int {
		$own = $this->find( $section );

		return ( null === $own ? 0 : $own['planned'] ) + array_sum( array_column( $this->children( $section ), 'planned' ) );
	}

	/**
	 * Apply the budget form.
	 *
	 * Input is flat: "{key}.label", "{key}.planned", "{key}.vat",
	 * "{key}.remove" for each line; "add.{section}.label" / ".planned" /
	 * ".vat" for a new sub-section; "add.*.label" / ".planned" / ".vat" for a
	 * new section; "move" = "{key}:up" or "{key}:down". A line missing from
	 * the input keeps its values.
	 *
	 * Planned amounts are kept including VAT, like the ledger's totals they
	 * are compared with. "{...}.vat" = "excluded" says the amount typed is
	 * before VAT: VAT at the given rate is added once, on this save.
	 *
	 * Removing a section removes its sub-sections and is refused while any
	 * of them has entries. Removing a sub-section moves its entries to its
	 * section (returned in "moved").
	 *
	 * @param array<string, string> $input   Form fields, sanitized as text.
	 * @param array<string, int>    $in_use  Entries per key.
	 * @param callable(): string    $new_key Makes a key not used in the case.
	 * @param int                   $vat_rate VAT rate for amounts typed before VAT, basis points.
	 * @return array{tree:self, moved:array<string, string>, errors:array<string, string>}
	 */
	public function edit( array $input, array $in_use, callable $new_key, int $vat_rate = 0 ): array {
		$errors   = array();
		$moved    = array();
		$sections = array();
		$children = array();

		foreach ( $this->lines as $line ) {
			$key    = $line['key'];
			$remove = '1' === ( $input[ $key . '.remove' ] ?? '' ) || ( '' !== $line['parent'] && isset( $sections[ $line['parent'] ]['remove'] ) );
			$edited = $line;

			if ( ! $remove ) {
				$edited['label']   = self::label_input( $input, $key . '.label', $line['label'], $errors );
				$edited['planned'] = self::amount_input( $input, $key, $line['planned'], $errors, $vat_rate );
			}

			if ( '' === $line['parent'] ) {
				$sections[ $key ] = array( 'line' => $edited ) + ( $remove ? array( 'remove' => true ) : array() );
				continue;
			}

			if ( $remove && ! isset( $sections[ $line['parent'] ]['remove'] ) ) {
				if ( ( $in_use[ $key ] ?? 0 ) > 0 ) {
					$moved[ $key ] = $line['parent'];
				}
				continue;
			}

			$children[ $line['parent'] ][] = $edited + ( $remove ? array( 'remove' => true ) : array() );
		}

		foreach ( $sections as $key => $section ) {
			if ( ! isset( $section['remove'] ) ) {
				continue;
			}

			$used = ( $in_use[ $key ] ?? 0 ) + array_sum( array_map( static fn( array $child ): int => $in_use[ $child['key'] ] ?? 0, $children[ $key ] ?? array() ) );

			if ( $used > 0 ) {
				$errors[ $key . '.remove' ] = 'in_use';
			}
		}

		$this->add_children( $input, $sections, $children, $new_key, $errors, $vat_rate );
		$this->add_section( $input, $sections, $new_key, $errors, $vat_rate );

		$kept = array_filter( $sections, static fn( array $section ): bool => ! isset( $section['remove'] ) );

		if ( count( $kept ) > self::MAX_SECTIONS ) {
			$errors[ 'add.' . self::NEW_SECTION . '.label' ] = 'too_many';
		}

		self::check_duplicates(
			array_map( static fn( array $section ): array => array( (string) $section['line']['label'], (string) ( $section['field'] ?? $section['line']['key'] ) ), array_values( $kept ) ),
			$errors
		);

		foreach ( $children as $section => $list ) {
			$list = array_values( array_filter( $list, static fn( array $child ): bool => ! isset( $child['remove'] ) ) );

			if ( count( $list ) > self::MAX_CHILDREN ) {
				$errors[ 'add.' . $section . '.label' ] = 'too_many';
			}

			self::check_duplicates(
				array_map( static fn( array $child ): array => array( (string) $child['label'], (string) ( $child['field'] ?? $child['key'] ) ), $list ),
				$errors
			);
			$children[ $section ] = $list;
		}

		if ( array() !== $errors ) {
			return array(
				'tree'   => $this,
				'moved'  => array(),
				'errors' => $errors,
			);
		}

		return array(
			'tree'   => self::assemble( array_column( $kept, 'line' ), $children, (string) ( $input['move'] ?? '' ) ),
			'moved'  => $moved,
			'errors' => array(),
		);
	}

	/**
	 * New sub-sections from the "add" rows.
	 *
	 * @param array<string, string>                           $input    Form.
	 * @param array<string, array<string, mixed>>             $sections Sections.
	 * @param array<string, array<int, array<string, mixed>>> $children Sub-sections per section, by reference.
	 * @param callable(): string                              $new_key  Key maker.
	 * @param array<string, string>                           $errors   Errors, by reference.
	 * @param int                                             $vat_rate VAT rate, basis points.
	 */
	private function add_children( array $input, array $sections, array &$children, callable $new_key, array &$errors, int $vat_rate ): void {
		foreach ( $sections as $key => $section ) {
			$field = 'add.' . $key;
			$label = Text::line( (string) ( $input[ $field . '.label' ] ?? '' ) );

			if ( '' === $label || isset( $section['remove'] ) ) {
				continue;
			}

			$children[ $key ][] = array(
				'key'     => $new_key(),
				'parent'  => $key,
				'label'   => self::label_input( $input, $field . '.label', '', $errors ),
				'planned' => self::amount_input( $input, $field, 0, $errors, $vat_rate ),
				'field'   => $field,
			);
		}
	}

	/**
	 * A new section from the "add section" row.
	 *
	 * @param array<string, string>               $input    Form.
	 * @param array<string, array<string, mixed>> $sections Sections, by reference.
	 * @param callable(): string                  $new_key  Key maker.
	 * @param array<string, string>               $errors   Errors, by reference.
	 * @param int                                 $vat_rate VAT rate, basis points.
	 */
	private function add_section( array $input, array &$sections, callable $new_key, array &$errors, int $vat_rate ): void {
		$field = 'add.' . self::NEW_SECTION;
		$label = Text::line( (string) ( $input[ $field . '.label' ] ?? '' ) );

		if ( '' === $label ) {
			if ( '' !== trim( (string) ( $input[ $field . '.planned' ] ?? '' ) ) ) {
				$errors[ $field . '.label' ] = 'required';
			}
			return;
		}

		$key              = $new_key();
		$sections[ $key ] = array(
			'line'  => array(
				'key'     => $key,
				'parent'  => '',
				'label'   => self::label_input( $input, $field . '.label', '', $errors ),
				'planned' => self::amount_input( $input, $field, 0, $errors, $vat_rate ),
			),
			'field' => $field,
		);
	}

	/**
	 * Put the edited lines back together, with one line moved.
	 *
	 * @param Line[]                                          $sections Kept sections, in order.
	 * @param array<string, array<int, array<string, mixed>>> $children Kept sub-sections per section.
	 * @param string                                          $move     "{key}:up", "{key}:down" or ''.
	 * @return self
	 */
	private static function assemble( array $sections, array $children, string $move ): self {
		[ $key, $direction ] = array_pad( explode( ':', $move, 2 ), 2, '' );
		$sections            = self::move( $sections, $key, $direction );
		$lines               = array();

		foreach ( $sections as $section ) {
			$lines[] = $section;

			foreach ( self::move( self::typed( $children[ $section['key'] ] ?? array() ), $key, $direction ) as $child ) {
				$lines[] = $child;
			}
		}

		return new self( $lines );
	}

	/**
	 * Lines without form-only fields.
	 *
	 * @param array<int, array<string, mixed>> $rows Lines.
	 * @return Line[]
	 */
	private static function typed( array $rows ): array {
		return array_map(
			static fn( array $line ): array => array(
				'key'     => (string) $line['key'],
				'parent'  => (string) $line['parent'],
				'label'   => (string) $line['label'],
				'planned' => (int) $line['planned'],
			),
			$rows
		);
	}

	/**
	 * Swap a line with its previous or next sibling.
	 *
	 * @param Line[] $siblings  Siblings.
	 * @param string $key       Line to move.
	 * @param string $direction up or down.
	 * @return Line[]
	 */
	private static function move( array $siblings, string $key, string $direction ): array {
		$siblings = array_values( $siblings );
		$index    = array_search( $key, array_column( $siblings, 'key' ), true );

		if ( false === $index ) {
			return $siblings;
		}

		$other = match ( $direction ) {
			'up'    => $index - 1,
			'down'  => $index + 1,
			default => -1,
		};

		if ( $other < 0 || $other >= count( $siblings ) ) {
			return $siblings;
		}

		[ $siblings[ $index ], $siblings[ $other ] ] = array( $siblings[ $other ], $siblings[ $index ] );

		return $siblings;
	}

	/**
	 * A label from the form.
	 *
	 * @param array<string, string> $input    Form.
	 * @param string                $field    Field.
	 * @param string                $fallback When the field is missing.
	 * @param array<string, string> $errors   Errors, by reference.
	 * @return string
	 */
	private static function label_input( array $input, string $field, string $fallback, array &$errors ): string {
		if ( ! array_key_exists( $field, $input ) ) {
			return $fallback;
		}

		$label = Text::line( (string) $input[ $field ] );

		if ( '' === $label ) {
			$errors[ $field ] = 'required';
		} elseif ( Text::length( $label ) > self::MAX_LABEL ) {
			$errors[ $field ] = 'too_long';
		}

		return $label;
	}

	/**
	 * A planned amount from the form, including VAT: empty is zero.
	 *
	 * @param array<string, string> $input    Form.
	 * @param string                $line     Line prefix: "{key}", "add.{section}" or "add.*".
	 * @param int                   $fallback When the field is missing.
	 * @param array<string, string> $errors   Errors, by reference.
	 * @param int                   $vat_rate VAT rate, basis points, when "{line}.vat" is "excluded".
	 * @return int Agorot.
	 */
	private static function amount_input( array $input, string $line, int $fallback, array &$errors, int $vat_rate ): int {
		$field = $line . '.planned';

		if ( ! array_key_exists( $field, $input ) ) {
			return $fallback;
		}

		$raw = trim( (string) $input[ $field ] );

		if ( '' === $raw ) {
			return 0;
		}

		$amount = Money::parse( $raw );

		if ( null !== $amount && $amount >= 0 && $amount <= Money::MAX && VatMode::Excluded->value === ( $input[ $line . '.vat' ] ?? '' ) ) {
			$amount = Vat::split( $amount, VatMode::Excluded, $vat_rate )['total'];
		}

		if ( null === $amount || $amount < 0 || $amount > Money::MAX ) {
			$errors[ $field ] = 'invalid';
			return 0;
		}

		return $amount;
	}

	/**
	 * Two siblings with the same label would be told apart by nobody.
	 *
	 * @param array<int, array{0:string, 1:string}> $siblings [label, form field] of the lines under one parent.
	 * @param array<string, string>                 $errors   Errors, by reference.
	 */
	private static function check_duplicates( array $siblings, array &$errors ): void {
		$seen = array();

		foreach ( $siblings as [ $label, $field ] ) {
			$label = mb_strtolower( $label );

			if ( '' === $label ) {
				continue;
			}

			if ( isset( $seen[ $label ] ) && ! isset( $errors[ $field . '.label' ] ) ) {
				$errors[ $field . '.label' ] = 'duplicate';
			}

			$seen[ $label ] = true;
		}
	}
}
