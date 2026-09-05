<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\V2\Chat\ChatDialogIdResolver;
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
		$dialogIdMap = $this->resolveDialogIdMap();
		if ($dialogIdMap !== null)
		{
			$option['dialogIdMap'] = $dialogIdMap;
		}

		$result = [];

		foreach ($this as $folder)
		{
			/** @var Folder $folder */
			$result[] = $folder->toRestFormat($option);
		}

		return $result;
	}

	/**
	 * Resolves dialogIds for every {@see PersonalFolder} chat across the whole collection in a
	 * single {@see ChatDialogIdResolver::resolve} call, so each folder's
	 * {@see PersonalFolder::toRestFormat} reads from the shared map instead of resolving on its
	 * own (prevents N+1). System folders have no composition and are skipped.
	 *
	 * Invariant: a collection is single-user (the only caller is `listAction → getByUser`), so the
	 * context user against whom private companions are resolved is taken from the folders
	 * themselves. Batch-resolving relies on this: a single context user is used for the whole map,
	 * and a private chat's companion is only unambiguous for a user who participates in it. If a
	 * future caller ever mixes folders of different users, we bail out of batching (return null)
	 * so each folder resolves its own chat ids against its own user in {@see PersonalFolder::toRestFormat}
	 * — correct, just not batched — instead of silently returning a wrong companion.
	 *
	 * @return array<int, string>|null chatId => dialogId, or null when there is nothing to resolve
	 */
	private function resolveDialogIdMap(): ?array
	{
		$allChatIds = [];
		$userId = 0;
		foreach ($this as $folder)
		{
			if (!$folder instanceof PersonalFolder)
			{
				continue;
			}
			$folderUserId = (int)($folder->getUserId() ?? 0);
			if ($userId === 0)
			{
				$userId = $folderUserId;
			}
			elseif ($folderUserId !== 0 && $folderUserId !== $userId)
			{
				// Single-user invariant broken (see docblock): do not batch across users.
				return null;
			}
			array_push($allChatIds, ...$folder->getChatIds());
		}

		if ($allChatIds === [] || $userId === 0)
		{
			return null;
		}

		return ServiceLocator::getInstance()
			->get(ChatDialogIdResolver::class)
			->resolve($allChatIds, $userId)
		;
	}
}
