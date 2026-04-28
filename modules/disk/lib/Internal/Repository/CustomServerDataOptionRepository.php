<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository;

use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Internal\Interface\CustomServerDataRepositoryInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;
use Throwable;

class CustomServerDataOptionRepository implements CustomServerDataRepositoryInterface
{
	/**
	 * {@inheritDoc}
	 * @throws ArgumentException
	 * @throws ArgumentOutOfRangeException
	 */
	public function create(CustomServerTypes $type, array $data): array
	{
		$data['id'] = $type->value;
		$data['type'] = $type->value;
		$key = $this->getKeyByType($type);

		$this->storeInternal($key, $data);

		return $data;
	}

	/**
	 * {@inheritDoc}
	 */
	public function find(mixed $id): ?array
	{
		$key = $this->getKeyById($id);

		return $this->findInternal($key);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getForTypes(array $types): array
	{
		return array_reduce(
			array: $types,
			callback: function (array $res, CustomServerTypes $type) {
				$data = $this->getForType($type);
				$res[$type->value] = $data;

				return $res;
			},
			initial: [],
		);
	}

	/**
	 * {@inheritDoc}
	 * At the moment, type === id, because we get data from options.
	 */
	public function getForType(CustomServerTypes $type): array
	{
		$key = $this->getKeyByType($type);
		$data = $this->findInternal($key);

		if (!is_array($data))
		{
			return [];
		}

		return [$data];
	}

	/**
	 * {@inheritDoc}
	 * @throws ArgumentException
	 * @throws ArgumentOutOfRangeException
	 */
	public function update(mixed $id, array $data): ?array
	{
		$key = $this->getKeyById($id);
		$oldData = $this->findInternal($key);

		if (!is_array($oldData))
		{
			return null;
		}

		$newData = array_merge($oldData, $data);
		$key = $this->getKeyById($id);

		$this->storeInternal($key, $newData);

		return $newData;
	}

	/**
	 * {@inheritDoc}
	 * @throws ArgumentNullException
	 * @throws ArgumentException
	 */
	public function delete(mixed $id): bool
	{
		$key = $this->getKeyById($id);

		Option::delete(
			moduleId: 'disk',
			filter: [
				'name' => $key,
			],
		);

		return true;
	}

	/**
	 * Get key for storage by type.
	 *
	 * @param CustomServerTypes $type
	 * @return string
	 */
	protected function getKeyByType(CustomServerTypes $type): string
	{
		return $this->getKeyById($type->value);
	}

	/**
	 * Get key for storage by id.
	 *
	 * @param mixed $id
	 * @return string
	 */
	protected function getKeyById(mixed $id): string
	{
		$id = strtolower((string)$id);

		return "custom_server_{$id}_data";
	}

	/**
	 * @param string $key
	 * @param array $data
	 * @return void
	 * @throws ArgumentException
	 * @throws ArgumentOutOfRangeException
	 */
	protected function storeInternal(string $key, array $data): void
	{
		$dataString = Json::encode($data);

		Option::set(
			moduleId: 'disk',
			name: $key,
			value: $dataString,
		);
	}

	/**
	 * @param string $key
	 * @return array|null
	 */
	protected function findInternal(string $key): ?array
	{
		$rawData = Option::get(
			moduleId: 'disk',
			name: $key,
		);

		if (!is_string($rawData) || $rawData === '')
		{
			return null;
		}

		try
		{
			return Json::decode($rawData);
		}
		catch (Throwable)
		{
			// TODO log?
			return null;
		}
	}
}
