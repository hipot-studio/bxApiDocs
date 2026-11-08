<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Security\Manager as SecurityManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\EntityPermissions\Item as ItemPermissions;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceNotFoundException;
use Bitrix\Crm\V2\Internal\Repository\Tracking\TraceRepository;

/**
 * The permission model of the traces: a trace has no rights of its own, it is seen through the items
 * it is bound to.
 *
 * Reading the attribution of an item follows the right to read the item; reading a trace follows the
 * right to read any existing item it is bound to; changing the attribution follows the right to update
 * every existing item it touches. A binding to an item that is gone counts for nothing: the binding
 * table has no foreign key and outlives the items.
 *
 * @internal
 */
final class TraceAccess
{
	private const BATCH_SIZE = 500;
	/** The bindings of a trace read at once to find a readable item of it. */
	private const READ_PAGE_SIZE = 100;

	/**
	 * @param int $readPageSize the bindings of a trace read at once by {@see assertCanReadTrace()}
	 */
	public function __construct(
		private readonly TraceRepository $traces = new TraceRepository(),
		private readonly int $readPageSize = self::READ_PAGE_SIZE,
	)
	{
	}

	/**
	 * @throws TraceNotFoundException the item does not exist or the user may not read it
	 */
	public function assertCanReadItem(int $userId, int $entityTypeId, int $entityId): void
	{
		if ($userId <= 0 || $entityId <= 0 || !\CCrmOwnerType::isCorrectEntityTypeId($entityTypeId))
		{
			throw new TraceNotFoundException();
		}

		$items = $this->resolveExisting([new ItemIdentifier($entityTypeId, $entityId)]);
		if (empty($items) || !$this->permissionsOf($userId)->canReadItemIdentifier($items[0]))
		{
			throw new TraceNotFoundException();
		}
	}

	/**
	 * The bindings are read page by page, the first ones first, and the reading stops at the first page
	 * with a readable existing item: a trace shared by many items costs a page, not all of them.
	 *
	 * @throws TraceNotFoundException the trace does not exist or none of its existing items is readable
	 */
	public function assertCanReadTrace(int $userId, int $traceId): void
	{
		if ($userId <= 0 || $traceId <= 0)
		{
			throw new TraceNotFoundException();
		}

		$pageSize = max(1, $this->readPageSize);
		$permissions = null;
		$afterBindingId = 0;
		do
		{
			$page = $this->traces->getBoundItemsPage($traceId, $afterBindingId, $pageSize);
			if (empty($page))
			{
				break;
			}

			$afterBindingId = $page[array_key_last($page)]['bindingId'];
			$items = $this->resolveExisting(array_filter(array_column($page, 'item')));
			if (empty($items))
			{
				continue;
			}

			$permissions ??= $this->permissionsOf($userId);
			self::preloadPermissionAttributes($permissions, $items);
			foreach ($items as $item)
			{
				if ($permissions->canReadItemIdentifier($item))
				{
					return;
				}
			}
		}
		while (count($page) === $pageSize);

		throw new TraceNotFoundException();
	}

	/**
	 * The trace is seen through its readable existing items, asked for its bindings as the caller has read
	 * them once.
	 *
	 * @param ItemIdentifier[] $items the items of the bindings of the trace
	 * @throws TraceNotFoundException there is no user, no existing item or none the user may read
	 */
	public function assertCanReadTraceOf(int $userId, array $items): void
	{
		$this->resolveReadable($userId, $items);
	}

	/**
	 * The rights to delete a trace, asked for its bindings as the caller has read them once: the trace is
	 * seen through its readable existing items, and each of its existing items has to be updatable.
	 *
	 * @param ItemIdentifier[] $items the items of the bindings of the trace
	 * @throws TraceNotFoundException there is no user, no existing item or none the user may read
	 * @throws TraceAccessDeniedException the user may not update one of the existing items
	 */
	public function assertCanDeleteTraceOf(int $userId, array $items): void
	{
		[$permissions, $existing] = $this->resolveReadable($userId, $items);
		self::assertCanUpdateExisting($permissions, $existing);
	}

	/**
	 * @param ItemIdentifier[] $items
	 * @return array{0: ItemPermissions, 1: ItemIdentifier[]} the permissions and the existing items, preloaded
	 * @throws TraceNotFoundException
	 */
	private function resolveReadable(int $userId, array $items): array
	{
		$items = $userId > 0 ? $this->resolveExisting($items) : [];
		if (empty($items))
		{
			throw new TraceNotFoundException();
		}

		$permissions = $this->permissionsOf($userId);
		self::preloadPermissionAttributes($permissions, $items);
		foreach ($items as $item)
		{
			if ($permissions->canReadItemIdentifier($item))
			{
				return [$permissions, $items];
			}
		}

		throw new TraceNotFoundException();
	}

	/**
	 * Items that do not exist are skipped.
	 *
	 * @param ItemIdentifier[] $items
	 * @throws TraceAccessDeniedException the user may not update one of the existing items
	 */
	public function assertCanModifyItems(int $userId, array $items): void
	{
		$items = $this->resolveExisting($items);
		if (empty($items))
		{
			return;
		}

		if ($userId <= 0)
		{
			throw new TraceAccessDeniedException();
		}

		$permissions = $this->permissionsOf($userId);
		self::preloadPermissionAttributes($permissions, $items);
		self::assertCanUpdateExisting($permissions, $items);
	}

	/**
	 * The items a trace is about to be bound to: all of them must exist. An item the user may not read is
	 * answered the way a missing one is, so the refusal does not tell that a hidden item exists; only an
	 * item the user reads but may not update is refused as denied.
	 *
	 * @param ItemIdentifier[] $items
	 * @throws TraceAccessDeniedException there is no user, or the user may not update one of the items
	 * @throws TraceNotFoundException one of the items does not exist or the user may not read it
	 */
	public function assertCanBindItems(int $userId, array $items): void
	{
		if ($userId <= 0)
		{
			throw new TraceAccessDeniedException();
		}

		$existing = $this->resolveExisting($items);
		if (count($existing) !== count(self::unique($items)))
		{
			throw new TraceNotFoundException();
		}

		$permissions = $this->permissionsOf($userId);
		self::preloadPermissionAttributes($permissions, $existing);
		foreach ($existing as $item)
		{
			if (!$permissions->canReadItemIdentifier($item))
			{
				throw new TraceNotFoundException();
			}
		}

		self::assertCanUpdateExisting($permissions, $existing);
	}

	/**
	 * @param ItemIdentifier[] $items existing items with their categories
	 * @throws TraceAccessDeniedException
	 */
	private static function assertCanUpdateExisting(ItemPermissions $permissions, array $items): void
	{
		foreach ($items as $item)
		{
			if (!$permissions->canUpdateItemIdentifier($item))
			{
				throw new TraceAccessDeniedException();
			}
		}
	}

	private function permissionsOf(int $userId): ItemPermissions
	{
		return Container::getInstance()->getUserPermissions($userId)->item();
	}

	/**
	 * Loads the permission attributes of the items with one query per type instead of one per item: the
	 * attributes are kept by the security controller of the type, whatever the category of the item. A type
	 * without a security controller is left to the check of each item.
	 *
	 * @param ItemIdentifier[] $items
	 */
	private static function preloadPermissionAttributes(ItemPermissions $permissions, array $items): void
	{
		$idsByType = [];
		foreach ($items as $item)
		{
			$idsByType[$item->getEntityTypeId()][] = $item->getEntityId();
		}

		foreach ($idsByType as $entityTypeId => $ids)
		{
			$permissionEntity = (new PermissionEntityTypeHelper($entityTypeId))->getPermissionEntityTypeForCategory(0);
			if (SecurityManager::resolveController($permissionEntity) === null)
			{
				continue;
			}

			foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk)
			{
				$permissions->preloadPermissionAttributes($entityTypeId, $chunk);
			}
		}
	}

	/**
	 * @param ItemIdentifier[] $items
	 * @return ItemIdentifier[]
	 */
	private static function unique(array $items): array
	{
		$unique = [];
		foreach ($items as $item)
		{
			$unique[$item->getEntityTypeId() . ':' . $item->getEntityId()] = $item;
		}

		return array_values($unique);
	}

	/**
	 * Leaves the items that exist, with their categories: the permissions of an item in a category are
	 * the permissions of that category.
	 *
	 * @param ItemIdentifier[] $items
	 * @return ItemIdentifier[]
	 */
	private function resolveExisting(array $items): array
	{
		$idsByType = [];
		foreach ($items as $item)
		{
			$idsByType[$item->getEntityTypeId()][$item->getEntityId()] = $item->getEntityId();
		}

		$existing = [];
		foreach ($idsByType as $entityTypeId => $ids)
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);
			if ($factory === null)
			{
				continue;
			}

			$categoryField =
				$factory->isCategoriesSupported()
					? $factory->getEntityFieldNameByMap(Item::FIELD_NAME_CATEGORY_ID)
					: null
			;
			$select = $categoryField === null ? ['ID'] : ['ID', $categoryField];

			foreach (array_chunk(array_values($ids), self::BATCH_SIZE) as $chunk)
			{
				$rows =
					$factory->getDataClass()::query()
						->setSelect($select)
						->whereIn('ID', $chunk)
						->fetchAll()
				;
				foreach ($rows as $row)
				{
					$existing[] = new ItemIdentifier(
						$entityTypeId,
						(int)$row['ID'],
						$categoryField === null ? null : (int)$row[$categoryField],
					);
				}
			}
		}

		return $existing;
	}
}
