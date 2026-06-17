<?php

namespace Bitrix\Mail\Helper\Mailbox\Options;

use Bitrix\Mail\Helper\Enum\Mailbox\EntityOptionsType;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Type\DateTime;

abstract class BaseOptionsHelper
{
	abstract protected static function getTableClass(): string;

	public static function getValue(
		int $mailboxId,
		EntityOptionsType $entityType,
		string $entityId,
		string $propertyName,
	): ?string
	{
		$values = static::getValues($mailboxId, $entityType, $entityId, [$propertyName]);

		return $values[$propertyName] ?? null;
	}

	/**
	 * @param string[] $propertyNames
	 * @return array<string, string> map propertyName => value (отсутствуют ключи без записей)
	 */
	public static function getValues(
		int $mailboxId,
		EntityOptionsType $entityType,
		string $entityId,
		array $propertyNames,
	): array
	{
		if ($propertyNames === [])
		{
			return [];
		}

		/** @var DataManager $table */
		$table = static::getTableClass();

		$rows = $table::query()
			->setSelect(['PROPERTY_NAME', 'VALUE'])
			->where('MAILBOX_ID', $mailboxId)
			->where('ENTITY_TYPE', $entityType->value)
			->where('ENTITY_ID', $entityId)
			->whereIn('PROPERTY_NAME', $propertyNames)
			->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$result[$row['PROPERTY_NAME']] = $row['VALUE'];
		}

		return $result;
	}

	public static function setValue(
		int $mailboxId,
		EntityOptionsType $entityType,
		string $entityId,
		string $propertyName,
		string $value,
	): void
	{
		/** @var DataManager $table */
		$table = static::getTableClass();

		$connection = $table::getEntity()->getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$primaryFields = ['MAILBOX_ID', 'ENTITY_TYPE', 'ENTITY_ID', 'PROPERTY_NAME'];

		$insertFields = [
			'MAILBOX_ID' => $mailboxId,
			'ENTITY_TYPE' => $entityType->value,
			'ENTITY_ID' => $entityId,
			'PROPERTY_NAME' => $propertyName,
			'VALUE' => $value,
			'DATE_INSERT' => new DateTime(),
		];

		$updateFields = [
			'VALUE' => $value,
		];

		[$merge] = $sqlHelper->prepareMerge(
			$table::getTableName(),
			$primaryFields,
			$insertFields,
			$updateFields,
		);

		if ($merge)
		{
			$connection->queryExecute($merge);
		}
	}
}
