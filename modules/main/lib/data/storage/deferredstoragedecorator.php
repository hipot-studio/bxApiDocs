<?php

namespace Bitrix\Main\Data\Storage;

use Bitrix\Main\Data\Internal\Storage\TtlValue;

class DeferredStorageDecorator implements StorageInterface
{
	public function __construct(private readonly StorageInterface $storage)
	{
	}

	private array $values = [];

	public function get(string $key, mixed $default = null): mixed
	{
		if (!array_key_exists($key, $this->values))
		{
			$value = $this->storage->get($key);

			$this->values[$key] = [
				'value' => $value,
				'existInDb' => !is_null($value),
			];
		}

		return $this->values[$key]['value'] ?? $default;
	}

	public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
	{
		$storageValue = new TtlValue($ttl);

		$this->values[$key] = [
			'value' => $value,
			'ttl' => $storageValue->getTtl(),
			'draft' => true,
			'existInDb' => $this->values[$key]['existInDb'] ?? false,
		];

		return true;
	}

	public function reset(string $key): void
	{
		unset($this->values[$key]);
	}

	public function delete(string $key): bool
	{
		if (
			!array_key_exists($key, $this->values)
			|| ($this->values[$key]['existInDb'] ?? false)
		)
		{
			$this->storage->delete($key);
		}
		$this->reset($key);

		return true;
	}

	public function save(): void
	{
		$valuesToSave = [];
		foreach ($this->values as $key => $data)
		{
			if (($data['draft'] ?? false) === true)
			{
				$valuesToSave[$data['ttl']][$key] = $data['value'];
			}
		}

		foreach ($valuesToSave as $ttl => $values)
		{
			if ($this->storage->setMultiple($values, $ttl))
			{
				foreach ($values as $key => $value)
				{
					$this->values[$key]['draft'] = false;
					$this->values[$key]['existInDb'] = true;
				}
			}
		}
	}

	public function clear(): bool
	{
		$this->values = [];

		return $this->storage->clear();
	}

	public function getMultiple(iterable $keys, mixed $default = null): iterable
	{
		$keys = is_array($keys) ? $keys : iterator_to_array($keys);

		$notLoadedKeys = array_values(array_diff($keys, array_keys($this->values)));

		$loadedValues = $this->storage->getMultiple($notLoadedKeys);

		foreach ($loadedValues as $key => $value)
		{
			$this->values[$key] = [
				'value' => $value,
				'existInDb' => !is_null($value),
			];
		}

		$result = [];
		foreach ($keys as $key)
		{
			$result[$key] = $this->values[$key]['value'] ?? $default;
		}

		return $result;
	}

	public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
	{
		foreach ($values as $key => $value)
		{
			$result = $this->set($key, $value, $ttl);
			if (!$result)
			{
				return false;
			}
		}

		return true;
	}

	public function deleteMultiple(iterable $keys): bool
	{
		foreach ($keys as $key)
		{
			$result = $this->delete($key);
			if (!$result)
			{
				return false;
			}
		}

		return true;
	}

	public function has($key): bool
	{
		return $this->get($key) !== null;
	}

	public function __destruct()
	{
		$this->save();
	}
}
