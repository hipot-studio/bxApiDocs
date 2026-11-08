<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Support;

use Bitrix\Main\ArgumentException;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoFieldsCollection;

/**
 * Maps a static catalog enumeration into a DTO collection: the code of a record becomes the
 * DTO id, the rest of the record — its named fields.
 */
final class EnumListMapper
{
	private const ID_FIELD = 'id';
	private const NAME_FIELD = 'name';

	/**
	 * @param class-string<Dto> $dtoClass
	 * @param array<int|string, string> $items code => label
	 * @param list<string> $select empty means every field
	 */
	public static function mapCollection(string $dtoClass, array $items, array $select = []): DtoCollection
	{
		$records = [];
		foreach ($items as $code => $label)
		{
			$records[$code] = [self::NAME_FIELD => (string)$label];
		}

		return self::mapFieldsCollection($dtoClass, $records, $select);
	}

	/**
	 * Same shape as {@see self::mapCollection()} for enumerations whose record carries
	 * several values instead of a single label.
	 *
	 * @param class-string<Dto> $dtoClass
	 * @param array<int|string, array<string, mixed>> $items code => dto field values
	 * @param list<string> $select empty means every field
	 * @throws ArgumentException when the DTO does not declare a field the enumeration carries
	 */
	public static function mapFieldsCollection(string $dtoClass, array $items, array $select = []): DtoCollection
	{
		$collection = new DtoCollection($dtoClass);

		$fields = $dtoClass::create()->getFields();
		$emptySelect = $select === [];
		$withId = $emptySelect || in_array(self::ID_FIELD, $select, true);
		$hasIntCode = self::hasIntCode($dtoClass, $fields);

		foreach ($items as $code => $values)
		{
			$dto = new $dtoClass();

			if ($withId)
			{
				$dto->{self::ID_FIELD} = $hasIntCode ? (int)$code : (string)$code;
			}

			foreach ($values as $field => $value)
			{
				self::requireField($dtoClass, $fields, $field);

				if ($emptySelect || in_array($field, $select, true))
				{
					$dto->{$field} = $value;
				}
			}

			$collection->add($dto);
		}

		return $collection;
	}

	/**
	 * The code of a record always becomes the DTO id, so the DTO has to declare it: the type
	 * of that field tells whether the code is an integer one.
	 *
	 * @param class-string<Dto> $dtoClass
	 * @throws ArgumentException
	 */
	private static function hasIntCode(string $dtoClass, DtoFieldsCollection $fields): bool
	{
		self::requireField($dtoClass, $fields, self::ID_FIELD);

		return $fields[self::ID_FIELD]->getPropertyType() === 'int';
	}

	/**
	 * Dto::__set() drops a value whose field is not declared without a word, which would turn
	 * a typo in an enumeration key into a field silently missing from the response.
	 *
	 * @param class-string<Dto> $dtoClass
	 * @throws ArgumentException
	 */
	private static function requireField(string $dtoClass, DtoFieldsCollection $fields, string $field): void
	{
		if (!isset($fields[$field]))
		{
			throw new ArgumentException(
				sprintf('Dto %s has no field %s', $dtoClass, $field),
				$field,
			);
		}
	}
}
