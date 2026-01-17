<?php

namespace Bitrix\Main\Data\Storage;

use Bitrix\Main\Data\Internal\Storage\PersistentStorageTable;
use Bitrix\Main\NotSupportedException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Data\Internal\Storage\TtlValue;
use Bitrix\Main\Data\Storage\Exception\StorageException;

class ConnectionBasedPersistentStorage implements PersistentStorageInterface
{
	public function get(string $key, mixed $default = null): mixed
	{
		$value = PersistentStorageTable::query()
			->where('KEY', $key)
			->where('EXPIRED_AT', '>', new DateTime())
			->setSelect(['VALUE'])
			->fetchObject()
			?->getValue()
		;

		return $value ?? $default;
	}

	public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
	{
		$storageValue = new TtlValue($ttl);

		$result = PersistentStorageTable::add([
			'KEY' => $key,
			'VALUE' => $value,
			'EXPIRED_AT' => $storageValue->getExpiredAt(),
		]);

		if (!$result->isSuccess())
		{
			throw new StorageException('Could not save value to storage: ' . implode('; ', $result->getErrorMessages()));
		}

		return true;
	}

	public function delete(string $key): bool
	{
		$result = PersistentStorageTable::delete($key);

		if (!$result->isSuccess())
		{
			throw new StorageException('Could not delete value from storage: ' . implode('; ', $result->getErrorMessages()));
		}

		return true;
	}

	public function clear(): bool
	{
		throw new NotSupportedException('Storage clear is not allowed');
	}

	public function getMultiple(iterable $keys, mixed $default = null): iterable
	{
		$keys = is_array($keys) ? $keys : iterator_to_array($keys);
		if (empty($keys))
		{
			return [];
		}

		$value = PersistentStorageTable::query()
			->whereIn('KEY', $keys)
			->setSelect(['KEY', 'VALUE'])
			->exec()
		;
		$rawValues = [];
		while ($row = $value->fetchObject())
		{
			$rawValues[$row->getKey()] = $row->getValue();
		}

		$result = [];
		foreach ($keys as $key)
		{
			$result[$key] = $rawValues[$key] ?? $default;
		}

		return $result;
	}

	public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
	{
		foreach ($values as $key => $value)
		{
			$this->set($key, $value, $ttl);
		}

		return true;
	}

	public function deleteMultiple(iterable $keys): bool
	{
		$keys = is_array($keys) ? $keys : iterator_to_array($keys);
		if (empty($keys))
		{
			return false;
		}

		PersistentStorageTable::deleteByFilter(
			(new ConditionTree())
				->whereIn('KEY', $keys)
		);

		return true;
	}

	public function has(string $key): bool
	{
		return $this->get($key) !== null;
	}
}
