<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\V2\Collection;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Typed collection of {@see Folder}-implementing objects.
 *
 * @method Folder|null offsetGet($offset)
 * @extends Collection<BaseFolder>
 */
class FolderCollection extends Collection implements RestConvertible
{
	public static function getCollectionElementClass(): string
	{
		return BaseFolder::class;
	}

	public static function getRestEntityName(): string
	{
		return 'folders';
	}

	public static function find(array $filter, array $order, ?int $limit = null, ?Context $context = null): self
	{
		$query = FolderTable::query()
			->setSelect(['*'])
			->setOrder($order ?: ['SORT' => 'ASC', 'ID' => 'ASC'])
		;

		foreach ($filter as $field => $value)
		{
			if (is_int($field))
			{
				continue;
			}

			$query->where(ltrim($field, '='), $value);
		}

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		$collection = new static();
		$factory = ServiceLocator::getInstance()->get(FolderFactory::class);

		foreach ($query->fetchAll() as $row)
		{
			$folder = $factory->createFromRow($row);
			if ($folder !== null && $folder->getPrimaryId())
			{
				$collection->add($folder);
			}
		}

		return $collection;
	}

	public function onlyAvailable(int $userId): self
	{
		$filtered = new static();
		foreach ($this as $folder)
		{
			if ($folder instanceof SystemFolder && !$folder->isAvailable($userId))
			{
				continue;
			}
			$filtered->add($folder);
		}

		return $filtered;
	}

	public function countPersonal(): int
	{
		$count = 0;
		foreach ($this as $folder)
		{
			if ($folder instanceof PersonalFolder)
			{
				$count++;
			}
		}

		return $count;
	}

	public function getMaxSort(): int
	{
		$max = 0;
		foreach ($this as $folder)
		{
			$sort = $folder->getSort();
			if ($sort > $max)
			{
				$max = $sort;
			}
		}

		return $max;
	}

	public function toRestFormat(array $option = []): array
	{
		$result = [];

		foreach ($this as $folder)
		{
			/** @var Folder $folder */
			$result[] = $folder->toRestFormat($option);
		}

		return $result;
	}
}
