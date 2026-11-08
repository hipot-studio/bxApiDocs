<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity;

use Bitrix\Main\ArgumentException;

/**
 * Opaque carrier of the serialized fields of an activity: the settings, the provider params and
 * the provider data.
 *
 * WHO MAY TOUCH IT. Only the provider layer, which owns the meaning of the content, and the
 * repository, which stores it. The typed model has no serialized fields, and nothing outside the
 * two owners interprets what is in here.
 *
 * WHY IT STAYS OPAQUE. The content is whatever the writers put there: a value unpacked from the
 * database with object instantiation forbidden comes back as a placeholder that is neither an
 * array nor false, so a reader that probes it with is_array() and assumes the rest gets warnings.
 * This class therefore neither inspects, nor merges, nor normalizes anything - a value set here
 * replaces the previous one whole, exactly as the storage semantics require.
 *
 * @internal
 */
final class ProviderPayload
{
	public const settings = 'settings';
	public const providerParams = 'providerParams';
	public const providerData = 'providerData';

	private const FIELDS = [
		self::settings,
		self::providerParams,
		self::providerData,
	];

	/** @var array<string, mixed> */
	private array $values = [];

	/**
	 * @return string[]
	 */
	public static function getSupportedFields(): array
	{
		return self::FIELDS;
	}

	/**
	 * Whether a value was given for the field. Tells "not given" from "given as null", which the
	 * repository needs: a field nobody set is not written at all.
	 */
	public function has(string $field): bool
	{
		self::assertField($field);

		return array_key_exists($field, $this->values);
	}

	/**
	 * @throws ArgumentException when no value was given for the field.
	 */
	public function get(string $field): mixed
	{
		if (!$this->has($field))
		{
			throw new ArgumentException("No value of '{$field}' was given to the provider payload");
		}

		return $this->values[$field];
	}

	/**
	 * Replaces the value of the field whole - the settings are never merged into the stored ones.
	 */
	public function set(string $field, mixed $value): static
	{
		self::assertField($field);
		$this->values[$field] = $value;

		return $this;
	}

	public function remove(string $field): static
	{
		self::assertField($field);
		unset($this->values[$field]);

		return $this;
	}

	/**
	 * Every value that was given, by field name. The projection onto the stored columns belongs
	 * to the field map, not here.
	 *
	 * @return array<string, mixed>
	 */
	public function getValues(): array
	{
		return $this->values;
	}

	private static function assertField(string $field): void
	{
		if (!in_array($field, self::FIELDS, true))
		{
			throw new ArgumentException("'{$field}' is not a field of the provider payload");
		}
	}
}
