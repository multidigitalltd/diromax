<?php
/**
 * Ledger entry validation.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a submitted ledger entry against an allowlist (standard 4.1).
 *
 * Input is the unslashed form data as strings. Output is either an EntryDraft
 * or a map of field => error code; the admin layer turns codes into messages.
 * Fields that do not belong to the chosen type are ignored, never stored.
 */
final class EntryValidator {

	/** Payment methods a client payment may use. */
	public const PAYMENT_METHODS = array( 'transfer', 'check', 'card', 'cash', 'other' );

	/** Maximum lengths, in characters. */
	private const MAX_DESCRIPTION = 200;
	private const MAX_PAYEE       = 120;
	private const MAX_REFERENCE   = 60;
	private const MAX_DETAILS     = 500;
	private const MAX_NOTE        = 1000;

	/** Earliest entry date accepted. */
	private const MIN_DATE = '2000-01-01';

	/**
	 * Constructor.
	 *
	 * @param string[] $categories Allowed budget keys: the case's sections and sub-sections.
	 * @param int      $vat_rate   Current VAT rate, basis points.
	 * @param string   $today      Today, Y-m-d.
	 */
	public function __construct(
		private readonly array $categories,
		private readonly int $vat_rate,
		private readonly string $today,
	) {}

	/**
	 * Validate submitted data.
	 *
	 * @param array<string, string> $input Field => raw string value.
	 * @return EntryDraft|array<string, string> Draft on success, field => error code otherwise.
	 */
	public function validate( array $input ): EntryDraft|array {
		$errors = array();
		$type   = EntryType::tryFrom( $input['type'] ?? '' );

		if ( null === $type ) {
			return array( 'type' => 'invalid' );
		}

		$date        = $this->date( $input['entry_date'] ?? '', 'entry_date', $errors );
		$amount      = $this->amount( $input['amount'] ?? '', $errors );
		$description = $this->text( $input['description'] ?? '', 'description', self::MAX_DESCRIPTION, true, $errors );
		$note        = $this->text( $input['internal_note'] ?? '', 'internal_note', self::MAX_NOTE, false, $errors, true );
		$reference   = $this->text( $input['reference'] ?? '', 'reference', self::MAX_REFERENCE, false, $errors );
		$fields      = $this->type_fields( $type, $input, $date, $errors );

		if ( $errors ) {
			return $errors;
		}

		$vat_mode = EntryType::Expense === $type ? ( VatMode::tryFrom( $input['vat_mode'] ?? '' ) ?? VatMode::Included ) : VatMode::Exempt;
		$rate     = VatMode::Exempt === $vat_mode ? 0 : $this->vat_rate;
		$split    = Vat::split( (int) $amount, $vat_mode, $rate );

		return new EntryDraft(
			type: $type,
			status: $fields['status'],
			entry_date: $date,
			due_date: $fields['due_date'],
			category: $fields['category'],
			payee: $fields['payee'],
			description: $description,
			net: $split['net'],
			vat: $split['vat'],
			total: $split['total'],
			vat_rate: $rate,
			funding: $fields['funding'],
			pay_to: $fields['pay_to'],
			method: $fields['method'],
			reference: $reference,
			payment_details: $fields['payment_details'],
			internal_note: $note,
			visible: self::checked( $input, 'visible' ),
			notify: self::checked( $input, 'notify' ),
		);
	}

	/**
	 * Fields that depend on the entry type.
	 *
	 * @param EntryType             $type   Entry type.
	 * @param array<string, string> $input  Raw input.
	 * @param string                $date   Validated entry date ('' when invalid).
	 * @param array<string, string> $errors Errors, by reference.
	 * @return array{status:EntryStatus, due_date:string|null, category:string, payee:string, funding:Funding|null, pay_to:PayTo|null, method:string, payment_details:string}
	 */
	private function type_fields( EntryType $type, array $input, string $date, array &$errors ): array {
		$fields = array(
			'status'          => EntryStatus::Paid,
			'due_date'        => null,
			'category'        => '',
			'payee'           => '',
			'funding'         => null,
			'pay_to'          => null,
			'method'          => '',
			'payment_details' => '',
		);

		switch ( $type ) {
			case EntryType::Expense:
				$status = EntryStatus::tryFrom( $input['status'] ?? '' );
				if ( EntryStatus::Paid !== $status && EntryStatus::Committed !== $status ) {
					$errors['status'] = 'invalid';
				}
				$fields['status']   = $status ?? EntryStatus::Paid;
				$fields['category'] = $this->category( $input['category'] ?? '', true, $errors );
				$fields['payee']    = $this->text( $input['payee'] ?? '', 'payee', self::MAX_PAYEE, true, $errors );
				$fields['funding']  = Funding::tryFrom( $input['funding'] ?? '' );
				if ( null === $fields['funding'] ) {
					$errors['funding'] = 'invalid';
				}
				break;

			case EntryType::Payment:
				$method = $input['method'] ?? '';
				if ( ! in_array( $method, self::PAYMENT_METHODS, true ) ) {
					$errors['method'] = 'invalid';
				}
				$fields['method'] = $method;
				break;

			case EntryType::Refund:
				break;

			case EntryType::Request:
				$fields['status']          = EntryStatus::Open;
				$fields['pay_to']          = PayTo::tryFrom( $input['pay_to'] ?? '' );
				$fields['due_date']        = $this->due_date( $input['due_date'] ?? '', $date, $errors );
				$fields['category']        = $this->category( $input['category'] ?? '', false, $errors );
				$fields['payment_details'] = $this->text( $input['payment_details'] ?? '', 'payment_details', self::MAX_DETAILS, false, $errors, true );
				if ( null === $fields['pay_to'] ) {
					$errors['pay_to'] = 'invalid';
				}
				$fields['payee'] = $this->text( $input['payee'] ?? '', 'payee', self::MAX_PAYEE, PayTo::Supplier === $fields['pay_to'], $errors );
				break;
		}

		return $fields;
	}

	/**
	 * A required calendar date within the accepted range.
	 *
	 * @param string                $value  Raw value.
	 * @param string                $field  Field name for the error.
	 * @param array<string, string> $errors Errors, by reference.
	 * @return string Y-m-d, or '' when invalid.
	 */
	private function date( string $value, string $field, array &$errors ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			$errors[ $field ] = 'required';
			return '';
		}

		if ( ! self::is_date( $value ) || $value < self::MIN_DATE || $value > $this->latest_date() ) {
			$errors[ $field ] = 'invalid';
			return '';
		}

		return $value;
	}

	/**
	 * Due date of a request: required, a valid date, not before the entry date.
	 *
	 * @param string                $value  Raw value.
	 * @param string                $date   Entry date ('' when invalid).
	 * @param array<string, string> $errors Errors, by reference.
	 * @return string|null
	 */
	private function due_date( string $value, string $date, array &$errors ): ?string {
		$due = $this->date( $value, 'due_date', $errors );

		if ( '' === $due ) {
			return null;
		}

		if ( '' !== $date && $due < $date ) {
			$errors['due_date'] = 'before_entry';
			return null;
		}

		return $due;
	}

	/**
	 * A positive amount up to Money::MAX.
	 *
	 * @param string                $value  Raw value.
	 * @param array<string, string> $errors Errors, by reference.
	 * @return int|null Agorot.
	 */
	private function amount( string $value, array &$errors ): ?int {
		if ( '' === trim( $value ) ) {
			$errors['amount'] = 'required';
			return null;
		}

		$agorot = Money::parse( $value );

		if ( null === $agorot || $agorot <= 0 || $agorot > Money::MAX ) {
			$errors['amount'] = 'invalid';
			return null;
		}

		return $agorot;
	}

	/**
	 * A budget category from the allowlist.
	 *
	 * @param string                $value    Raw value.
	 * @param bool                  $required Whether empty is an error.
	 * @param array<string, string> $errors   Errors, by reference.
	 * @return string
	 */
	private function category( string $value, bool $required, array &$errors ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			if ( $required ) {
				$errors['category'] = 'required';
			}
			return '';
		}

		if ( ! in_array( $value, $this->categories, true ) ) {
			$errors['category'] = 'invalid';
			return '';
		}

		return $value;
	}

	/**
	 * Normalised text with a length limit.
	 *
	 * @param string                $value     Raw value.
	 * @param string                $field     Field name.
	 * @param int                   $max       Maximum length in characters.
	 * @param bool                  $required  Whether empty is an error.
	 * @param array<string, string> $errors    Errors, by reference.
	 * @param bool                  $multiline Keep line breaks.
	 * @return string
	 */
	private function text( string $value, string $field, int $max, bool $required, array &$errors, bool $multiline = false ): string {
		$value = $multiline ? Text::block( $value ) : Text::line( $value );

		if ( '' === $value && $required ) {
			$errors[ $field ] = 'required';
		} elseif ( Text::length( $value ) > $max ) {
			$errors[ $field ] = 'too_long';
		}

		return $value;
	}

	/**
	 * Latest accepted date: one year from today.
	 *
	 * @return string
	 */
	private function latest_date(): string {
		return ( new \DateTimeImmutable( $this->today ) )->modify( '+1 year' )->format( 'Y-m-d' );
	}

	/**
	 * Was a checkbox ticked? Only the exact value "1" counts.
	 *
	 * @param array<string, string> $input Raw input.
	 * @param string                $field Field name.
	 * @return bool
	 */
	private static function checked( array $input, string $field ): bool {
		return isset( $input[ $field ] ) && '1' === $input[ $field ];
	}

	/**
	 * Is the value a real Y-m-d calendar date?
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_date( string $value ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return false;
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}
}
