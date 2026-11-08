<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Requisite;

use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteColumnMap;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\Date;

/**
 * The set of fields one write of a requisite carries: the names of {@see Requisite}, the values already
 * in the form the storage keeps them in.
 *
 * The set is what it says and nothing more. A field it carries was named by the caller; a field it does
 * not carry was not. That difference is the whole reason the type exists: an empty string, a zero and a
 * `false` are values a caller may well mean, and a column cannot tell any of them from a field left out
 * of the call - so the set answers it instead, through {@see self::has()} rather than through the value.
 *
 * Values are normalized once, here, and never again downstream:
 *
 * - a boolean field takes a real `bool`, and a value that is none of the four legacy accepts (`'Y'`,
 *   `'N'`, `true`, `false`) becomes the default of that field - the same substitution legacy makes, kept
 *   because it is observable behaviour of the write and not a detail of the storage;
 * - an integer field takes an `int`, a moment in time takes {@see Date} or {@see \Bitrix\Main\Type\DateTime},
 *   everything else takes a `string`;
 * - `null` stays `null` on every field but a boolean one: a caller that names a field and gives it no
 *   value is emptying the cell, and only a boolean cell has no empty state to go to.
 *
 * Three fields have no counterpart in the read contract and exist for the write alone
 * ({@see RequisiteColumnMap::COLUMN_BY_WRITE_ONLY_FIELD}): the internal write mode of the address-only
 * requisite and the two file fields, which the write compares against their current values.
 *
 * @internal
 */
final readonly class RequisiteWriteData
{
	/**
	 * The internal write mode: a requisite that exists to carry an address and keeps no legal details of
	 * its own. Not a property of a requisite the contract publishes - it is the shape of the write - so
	 * it is named here rather than in {@see Requisite}.
	 */
	public const FIELD_ADDRESS_ONLY = 'addressOnly';

	/** The file of the signature; published by neither the read nor the write of the group. */
	public const FIELD_SIGNATURE_FILE_ID = 'signatureFileId';

	/** The file of the stamp; the same. */
	public const FIELD_STAMP_FILE_ID = 'stampFileId';

	/**
	 * The service code of an exchange. Published by neither the read nor the write of the group - it means
	 * nothing to a client - but the form of the card writes it, so a write has to be able to carry it.
	 */
	public const FIELD_CODE = 'code';

	/**
	 * @param array<string, int|bool|string|Date|null> $valueByField normalized, keyed by field name. A
	 *        key is a field the caller named, so the keys are the set and not just its filled part.
	 */
	private function __construct(private array $valueByField)
	{
	}

	/**
	 * @param array<string, mixed> $valueByField field name => value as the caller has it.
	 * @throws ArgumentException a name that is not a writable field of a requisite, or a value no
	 *         field of that name could hold.
	 */
	public static function of(array $valueByField): self
	{
		$normalized = [];
		foreach ($valueByField as $name => $value)
		{
			$field = (string)$name;
			$normalized[$field] = self::normalize($field, $value);
		}

		return new self($normalized);
	}

	/**
	 * The same set with $field named as well, or with its value replaced.
	 *
	 * @throws ArgumentException
	 */
	public function with(string $field, mixed $value): self
	{
		$valueByField = $this->valueByField;
		$valueByField[$field] = self::normalize($field, $value);

		return new self($valueByField);
	}

	/** Whether the caller named $field at all - which is not the same as it having a value. */
	public function has(string $field): bool
	{
		return array_key_exists($field, $this->valueByField);
	}

	/**
	 * The value of $field, and `null` both for a field emptied on purpose and for a field the caller
	 * never named. {@see self::has()} is what tells those two apart.
	 *
	 * @throws ArgumentException a name that is not a writable field of a requisite.
	 */
	public function get(string $field): int|bool|string|Date|null
	{
		RequisiteColumnMap::requireWriteColumn($field);

		return $this->valueByField[$field] ?? null;
	}

	/**
	 * The fields the caller named, in the order they were named.
	 *
	 * @return string[]
	 */
	public function fields(): array
	{
		return array_keys($this->valueByField);
	}

	public function isEmpty(): bool
	{
		return $this->valueByField === [];
	}

	/**
	 * @return array<string, int|bool|string|Date|null>
	 */
	public function toArray(): array
	{
		return $this->valueByField;
	}

	/**
	 * @throws ArgumentException
	 */
	private static function normalize(string $field, mixed $value): int|bool|string|Date|null
	{
		RequisiteColumnMap::requireWriteColumn($field);

		if (isset(RequisiteColumnMap::BOOL_FIELDS[$field]))
		{
			return self::normalizeBool($field, $value);
		}

		if ($value === null)
		{
			return null;
		}

		if (isset(RequisiteColumnMap::DATE_TIME_FIELDS[$field]))
		{
			if (!$value instanceof Date)
			{
				// Guessing a format here would make the write a parser of dates, and a wrong guess
				// writes a wrong moment rather than refusing. The caller holds the format it read the
				// value in, so it builds the type.
				throw new ArgumentException(
					sprintf('The requisite field `%s` takes a moment in time', $field),
					$field,
				);
			}

			return $value;
		}

		if (!is_scalar($value))
		{
			throw new ArgumentException(
				sprintf('Unsupported value of the requisite field `%s`: %s', $field, get_debug_type($value)),
				$field,
			);
		}

		return isset(RequisiteColumnMap::INT_FIELDS[$field]) ? (int)$value : (string)$value;
	}

	/**
	 * Legacy accepts four values on a boolean field and replaces anything else with the default of that
	 * field, which is how a requisite ends up active after a call that asked for `'1'`. The substitution
	 * is observable and stays; what changes is where it happens - once, here.
	 */
	private static function normalizeBool(string $field, mixed $value): bool
	{
		if ($value === true || $value === 'Y')
		{
			return true;
		}

		if ($value === false || $value === 'N')
		{
			return false;
		}

		return RequisiteColumnMap::BOOL_FIELD_DEFAULT[$field];
	}
}
