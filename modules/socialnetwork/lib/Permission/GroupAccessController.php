<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Permission;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Exception\UnknownActionException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Socialnetwork\Permission\Dto\GroupViewResult;
use Bitrix\Socialnetwork\Permission\Model\GroupModel;
use Bitrix\Socialnetwork\Permission\Query\GroupViewBatchQuery;
use Bitrix\Socialnetwork\Permission\Rule\MassRuleInterface;
use Bitrix\Socialnetwork\Permission\Trait\AccessErrorTrait;
use Bitrix\Socialnetwork\Permission\Trait\AccessUserTrait;

class GroupAccessController extends AbstractAccessController
{
	public const MAX_MASS_CHECK_ITEMS = GroupViewBatchQuery::MAX_GROUP_IDS;

	protected static array $cache = [];

	/**
	 * Returns view access results for every normalized container ID, including missing containers.
	 * An empty result means the normalized request was empty.
	 *
	 * @param int[] $itemsId
	 *
	 * @return array<int, GroupViewResult>
	 *
	 * @throws UnknownActionException the action does not support the mass check
	 */
	public static function getAccessibleGroups(
		int $userId,
		string $action,
		array $itemsId,
		mixed $params = null,
	): array
	{
		return (new static($userId))->checkByItemsId($action, $itemsId, $params);
	}

	/**
	 * @param int[] $itemsId
	 *
	 * @throws UnknownActionException
	 */
	public function checkByItemsId(string $action, array $itemsId, mixed $params = null): array
	{
		return $this->massCheck($action, $this->loadItems($itemsId), $params);
	}

	/**
	 * @param AccessibleItem[] $items
	 *
	 * @throws UnknownActionException
	 */
	public function massCheck(string $action, array $items, mixed $params = null): array
	{
		$this->assertMassCheckSize($items);

		$rule = $this->ruleFactory->createFromAction($action, $this);

		if (!$rule instanceof MassRuleInterface)
		{
			throw new UnknownActionException($action);
		}

		return $rule->executeMass($items, $params);
	}

	private function assertMassCheckSize(array $items): void
	{
		if (count($items) > self::MAX_MASS_CHECK_ITEMS)
		{
			throw new ArgumentException(
				'Mass access check accepts at most ' . self::MAX_MASS_CHECK_ITEMS . ' items per request.',
				'items',
			);
		}
	}

	public function getModel(array|Arrayable $data): AccessModelInterface
	{
		return GroupModel::createFromArray($data);
	}

	public function getDictionary(): AccessDictionaryInterface
	{
		return GroupDictionary::getInstance();
	}

	protected function loadItem(int $itemId = null): ?AccessibleItem
	{
		$itemId = (int)$itemId;
		if ($itemId === 0)
		{
			return new GroupModel();
		}

		$key = 'GROUP_' . $itemId;
		if (!isset(static::$cache[$key]))
		{
			static::$cache[$key] = GroupModel::createFromId($itemId);
		}

		return static::$cache[$key];
	}

	/**
	 * Keeps ordered unique positive IDs without populating the item cache.
	 *
	 * @param int[] $itemsId
	 *
	 * @return AccessibleItem[]
	 */
	protected function loadItems(array $itemsId): array
	{
		$items = [];

		foreach ($itemsId as $itemId)
		{
			$itemId = (int)$itemId;

			if ($itemId > 0 && !isset($items[$itemId]))
			{
				$items[$itemId] = GroupModel::createFromId($itemId);
			}
		}

		return array_values($items);
	}
}
