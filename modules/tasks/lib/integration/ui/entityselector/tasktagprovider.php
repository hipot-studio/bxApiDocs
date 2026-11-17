<?php

namespace Bitrix\Tasks\Integration\UI\EntitySelector;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Integration\UI\EntitySelector\Context\Context;
use Bitrix\Tasks\Internals\Registry\UserRegistry;
use Bitrix\Tasks\Internals\Task\EO_Label;
use Bitrix\Tasks\Internals\Task\LabelTable;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Internals\Task\MemberTable;
use Bitrix\Tasks\Internals\Task\TagCollection;
use Bitrix\Tasks\Internals\Task\TaskTagTable;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;
use CSite;
use CUser;

/**
 * Class TagProvider
 *
 * @package Bitrix\Tasks\Integration\UI\EntitySelector
 */
final class TaskTagProvider extends BaseProvider
{
	private const GROUP_LIMIT = 100;
	private const LIMIT = 30;
	private const MULTIPLIER = 50;
	private const FAULT = 5;

	/**
	 * Recent participation window of the member tag source: how many of the newest tasks of the user the
	 * candidate lookup collects tags from. It exists to bound that source - without it the set of tasks
	 * whose tags are collected is the whole participation history of the user, so both opening the filter
	 * picker and every search request in it would scale with that history.
	 */
	private const MEMBER_TASK_WINDOW = 1500;

	private const ENTITY_ID = 'task-tag';
	private const TAGS_TAB_ID = 'all';
	private const LABEL_ALIAS = 'LABEL_PROVIDER';
	private const TAGS_ORDER = 'desc nulls last';

	private int $userId;
	private int $taskId;
	private int $groupId;
	private bool $canPreselectTemplateTags = false;
	private string $context;
	private array $lastActivityTagIds = [];

	/**
	 * @throws LoaderException
	 */
	public function __construct(array $options = [])
	{
		parent::__construct();
		$this->taskId = (int)($options['taskId'] ?? 0);
		$this->groupId = (int)($options['groupId'] ?? 0);
		$this->userId = CurrentUser::get()->getId();
		$this->canPreselectTemplateTags = (bool)($options['canPreselectTemplateTags'] ?? false);
		$this->resolveContext($options);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function fillDialog(Dialog $dialog): void
	{
		$this->addTagsTab($dialog);
		($this->taskId > 0) && $dialog->addItems($this->getTagItems(true));
		$dialog->addItems($this->getTagItems());
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function getTags(bool $getSelected = false): TagCollection
	{
		if ($getSelected)
		{
			return $this->getSelectedTagCollection();
		}

		switch ($this->context)
		{
			case Context::FILTER:
				return $this->getFilterTagCollection();

			case Context::GROUP:
			case Context::USER:
			default:
				$lastActivityTagCollection = $this->getLastActivityTagCollection();
				$ownerTagCollection = $this->getOwnerTagCollection();
				$lastActivityTagCollection->mergeByName($ownerTagCollection);

				return $lastActivityTagCollection;
		}
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$searchQuery->setCacheable(false);

		switch ($this->context)
		{
			case Context::FILTER:
				$dialog->addItems($this->makeItems($this->getFilterTagCollection($searchQuery)));
				break;

			case Context::GROUP:
			case Context::USER:
			default:
				$dialog->addItems($this->makeItems($this->getOwnerFoundCollection($searchQuery)));
				break;
		}
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getSelectedTagCollection(): TagCollection
	{
		$query = LabelTable::query();
		$query->setSelect(array_merge($this->getSelect(), ['TASK_TAG.TASK_ID']));
		$query->where('TASK_TAG.TASK_ID', $this->taskId);
		$tagCollection = $query->exec()->fetchCollection();

		return $tagCollection;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private function getFilterTagCollection(SearchQuery $searchQuery = null): TagCollection
	{
		$contextTagsQuery = LabelTable::query();
		$contextTagsQuery
			->setSelect($this->getSelect())
			->setOrder(['ID' => 'DESC'])
			->setLimit(self::LIMIT)
		;

		if ($this->groupId > 0)
		{
			$contextTagsQuery
				->where('USER_ID', 0)
				->where('GROUP_ID', $this->groupId)
			;
		}
		else
		{
			$contextTagsQuery
				->where('USER_ID', $this->userId)
				->where('GROUP_ID', 0)
			;
		}

		!is_null($searchQuery) && $contextTagsQuery->whereLike('NAME', "%{$searchQuery->getQuery()}%");

		$tagCollection = $contextTagsQuery->exec()->fetchCollection();

		if ($this->groupId === 0 && $tagCollection->count() < self::LIMIT)
		{
			$groupTagsQuery = LabelTable::query();
			$groups = UserRegistry::getInstance($this->userId)->getUserGroups();
			$limit = self::GROUP_LIMIT;
			if (!is_null($searchQuery))
			{
				$groupTagsQuery->whereLike('NAME', "%{$searchQuery->getQuery()}%");
				$limit = null;
			}

			// No groups only means there are no group tags to add: the member tags below are collected
			// regardless, so a user outside every group still sees the tags of the tasks they take part in.
			$groupIds = array_slice(array_keys($groups), 0, $limit);
			if (!empty($groupIds))
			{
				$groupTagsQuery
					->setSelect($this->getSelect())
					->setOrder(['ID' => 'DESC'])
					->setLimit(self::LIMIT - $tagCollection->count())
					->where('USER_ID', 0)
					->whereIn('GROUP_ID', $groupIds)
				;

				$groupTagCollection = $groupTagsQuery->exec()->fetchCollection();
				$tagCollection->mergeByName($groupTagCollection);
			}
		}

		if ($this->groupId === 0 && $tagCollection->count() < self::LIMIT)
		{
			// Tags on tasks the user takes part in usually belong to the task author, so the personal/group
			// scope above never lists them; add them so the normal-list filter offers the names actually
			// present on the user's tasks instead of forcing manual entry. The backend filters by name.
			// The candidates come from a bounded window of the newest tasks of the user, so this source reads
			// at most self::MEMBER_TASK_WINDOW membership rows per role instead of the whole participation
			// history. The price: a tag that occurs only on tasks outside the window is neither offered nor
			// found by search here. The personal and group tags above still cover the whole history.
			$taskIds = $this->getRecentMemberTaskIds();
			if (!empty($taskIds))
			{
				$memberTagCollection = $this->getMemberTagCollection(
					$taskIds,
					self::LIMIT - $tagCollection->count(),
					$searchQuery,
				);
				$tagCollection->mergeByName($memberTagCollection);
			}
		}

		return $tagCollection;
	}

	/**
	 * The newest self::MEMBER_TASK_WINDOW tasks the user takes part in, read per role.
	 *
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getRecentMemberTaskIds(): array
	{
		$roles = MemberTable::possibleTypes();
		if (empty($roles))
		{
			return [];
		}

		// One query per role, because with USER_ID and TYPE fixed the rest of the USER_ID_TYPE index is the
		// primary key tail: ORDER BY TASK_ID DESC then walks the index backwards and reads no more than the
		// window per role, while a single query over every role has to sort the whole participation history
		// of the user to find its newest tasks. The result is the same, not an approximation: a task among
		// the newest MEMBER_TASK_WINDOW tasks of the user is a fortiori among the newest MEMBER_TASK_WINDOW
		// tasks of its own role, so the union over roles is a superset of the window, and the newest
		// MEMBER_TASK_WINDOW ids of that union are exactly the window.
		$roleTaskIds = [];
		foreach ($roles as $role)
		{
			$roleQuery = MemberTable::query();
			$roleQuery
				->setSelect(['TASK_ID'])
				->where('USER_ID', $this->userId)
				->where('TYPE', $role)
				->setOrder(['TASK_ID' => 'DESC'])
				->setLimit(self::MEMBER_TASK_WINDOW)
			;

			$roleTaskIds[] = array_column($roleQuery->fetchAll(), 'TASK_ID');
		}

		$taskIds = array_unique(array_merge(...$roleTaskIds));
		rsort($taskIds, SORT_NUMERIC);

		return array_slice($taskIds, 0, self::MEMBER_TASK_WINDOW);
	}

	/**
	 * The labels met on $taskIds, one per name and newest first, read by primary key.
	 *
	 * Two steps because the names are deduplicated by a grouping query and ORM forbids fetchCollection() on one:
	 * getMemberTagIds() resolves the representative ids, this reads the labels behind them. The order of that first
	 * step is restored by TagCollection::sort() - a lookup by primary key answers in its own order.
	 *
	 * @param int[] $taskIds
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getMemberTagCollection(array $taskIds, int $limit, ?SearchQuery $searchQuery): TagCollection
	{
		$tagIds = $this->getMemberTagIds($taskIds, $limit, $searchQuery);
		if (empty($tagIds))
		{
			return new TagCollection();
		}

		$query = LabelTable::query();
		$query
			->setSelect($this->getSelect())
			->whereIn('ID', $tagIds)
		;

		$tagCollection = $query->exec()->fetchCollection();
		$tagCollection->sort($tagIds);

		return $tagCollection;
	}

	/**
	 * At most $limit ids of tags met on $taskIds - one per tag name, the newest label carrying it - newest first.
	 *
	 * The names are deduplicated in the database and BEFORE the limit: labels of different owners share names
	 * freely, and DISTINCT over the selected (ID, NAME) keeps every one of them, so a handful of same-named labels
	 * would eat the remaining limit and push the other tags of the window out of the list. In the filter context a
	 * tag is its name - that is what makeItem() shows and what the search matches - so any label carrying it will
	 * do; the representative is the newest one, MAX(ID), which is also what the result is ordered by.
	 *
	 * The window is joined to the labels instead of being resolved into tag ids in PHP first, so that search,
	 * deduplication and the limit all happen in the database and only the resulting labels are read. The join keeps
	 * the link table on the TASK_ID index range of the window, while an IN subquery or an EXISTS over the same
	 * window is folded into a LooseScan of the whole link table instead: measured on 300K links, 5 ms against 31 ms.
	 *
	 * @param int[] $taskIds
	 *
	 * @return int[]
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getMemberTagIds(array $taskIds, int $limit, ?SearchQuery $searchQuery): array
	{
		$query = LabelTable::query();
		$query
			->addSelect(new ExpressionField('NEWEST_ID', 'MAX(%s)', 'ID'))
			->setGroup(['NAME'])
			->addOrder('NEWEST_ID', 'DESC')
			->setLimit($limit)
			->whereIn('TASK_TAG.TASK_ID', $taskIds)
		;

		!is_null($searchQuery) && $query->whereLike('NAME', "%{$searchQuery->getQuery()}%");

		return array_map('intval', array_column($query->fetchAll(), 'NEWEST_ID'));
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getLastActivityTagCollection(): TagCollection
	{
		$this->lastActivityTagIds = $this->getLastActivityTagIds();
		if (empty($this->lastActivityTagIds))
		{
			return new TagCollection();
		}
		$lastActivityQuery = LabelTable::query();
		$lastActivityQuery
			->setSelect($this->getSelect())
			->whereIn('ID', $this->lastActivityTagIds)
		;
		$lastActivityTagCollection = $lastActivityQuery->exec()->fetchCollection();
		$lastActivityTagCollection->sort($this->lastActivityTagIds);

		return $lastActivityTagCollection;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getOwnerTagCollection(): TagCollection
	{
		$count = count($this->lastActivityTagIds);
		if ($count >= self::LIMIT)
		{
			return new TagCollection();
		}
		$query = LabelTable::query();
		$query->where($this->getOwnerFilter());
		$query
			->setSelect($this->getSelect())
			->setLimit(self::LIMIT - $count)
		;
		($count > 0) && $query->whereNotIn('ID', $this->lastActivityTagIds);
		$tagCollection = $query->exec()->fetchCollection();

		return $tagCollection;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getOwnerFoundCollection(SearchQuery $searchQuery): TagCollection
	{
		$query = LabelTable::query();
		$query
			->where($this->getOwnerFilter())
			->setSelect($this->getSelect())
			->whereLike('NAME', "%{$searchQuery->getQuery()}%")
		;
		$tagCollection = $query->exec()->fetchCollection();

		return $tagCollection;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getLastActivityTagIds(): array
	{
		$lastActivityQuery = TaskTagTable::query();
		$lastActivityQuery
			->setCustomBaseTableAlias(TaskTagTable::getTableName())
			->where($this->getOwnerFilter(self::LABEL_ALIAS . '.'))
			->registerRuntimeField(
				'',
				(new Reference(
					self::LABEL_ALIAS,
					LabelTable::class,
					Join::on('this.TAG_ID', 'ref.ID')
				))->configureJoinType(Join::TYPE_INNER)
			)
		;

		$distinctQuery = clone $lastActivityQuery;

		$lastActivityQuery
			->setOrder(['ID' => 'DESC'])
			->setSelect(['TAG_ID'])
			->setLimit(self::LIMIT * self::MULTIPLIER)
		;
		$tagCollection = $lastActivityQuery->exec()->fetchCollection();
		$tagIds = array_unique($tagCollection->getTagIdList());
		$tagIdsCount = count($tagIds);
		if ($tagIdsCount + self::FAULT >= self::LIMIT)
		{
			return array_splice($tagIds, 0, min($tagIdsCount, self::LIMIT));
		}

		// if I am unlucky :( going to full scan with distinct...
		$distinctQuery
			->addSelect(new ExpressionField('U_TAG_ID', 'DISTINCT(TAG_ID)'))
			->addSelect(new ExpressionField('M_ID', 'MAX(' . TaskTagTable::getTableName() . '.ID)'))
			->setGroup(['TAG_ID'])
			->addOrder('M_ID', 'DESC')
			->setLimit(self::LIMIT)
		;
		$tagCollection = $distinctQuery->exec()->fetchAll();
		$tagIds = array_column($tagCollection, 'U_TAG_ID');

		return array_map('intval', $tagIds);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getTagItems(bool $getSelected = false): array
	{
		return $this->makeItems($this->getTags($getSelected), $getSelected);
	}

	public function makeItems(TagCollection $tags, bool $getSelected = false): array
	{
		$result = [];
		foreach ($tags as $tag)
		{
			$result[] = $this->makeItem($tag, $getSelected);
		}

		return $result;
	}

	public function makeItem(EO_Label $tag, bool $isSelected = false): Item
	{
		$isFilterContext = $this->context === Context::FILTER;
		$itemData = [
			'id' => $isFilterContext ? $tag->getName() : $tag->getId(),
			'badges' => $isFilterContext ? null: $this->getBadge($tag),
			'selected' => $isFilterContext ? false : $isSelected,
			'title' => $tag->getName(),
			'entityId' => self::ENTITY_ID,
			'tabs' => [self::TAGS_TAB_ID],
		];

		return new Item($itemData);
	}

	private function addTagsTab(Dialog $dialog): void
	{
		$dialog->addTab(
			new Tab([
				'id' => self::TAGS_TAB_ID,
				'title' => Loc::getMessage('TASKS_UI_ENTITY_SELECTOR_TAG_TAB'),
				'stub' => true,
				'itemOrder' => [
					'sort' => self::TAGS_ORDER,
				],
			])
		);
	}

	/**
	 * @throws LoaderException
	 */
	private function resolveContext(array $options): void
	{
		if (($options['filter'] ?? null))
		{
			$this->context = Context::FILTER;
		}
		elseif ($this->groupId > 0 && Loader::includeModule('socialnetwork'))
		{
			$this->context = Context::GROUP;
		}
		else
		{
			$this->context = Context::USER;
		}
	}

	private function getBadge(EO_Label $tag): array
	{
		$owner = '';
		switch ($this->context)
		{
			case Context::GROUP:
				$group = $tag->getGroup();
				if (isset($group))
				{
					$owner = $group->getName();
				}
				break;

			case Context::USER:
				$user = $tag->getUser();
				if (isset($user))
				{
					$owner = CUser::FormatName(
						CSite::GetNameFormat(),
						[
							'LOGIN' => $user->getLogin(),
							'NAME' => $user->getName(),
							'LAST_NAME' => $user->getLastName(),
							'SECOND_NAME' => $user->getSecondName(),
						],
						true,
					);
				}
				break;
		}

		return [
			[
				'title' => $owner,
			],
			[
				'textColor' => '#bb8412',
			],
			[
				'bgColor' => '#fff599',
			],
		];
	}

	private function getSelect(): array
	{
		switch ($this->context)
		{
			case Context::FILTER:
				return self::getFilterContextSelect();

			case Context::GROUP:
				return self::getGroupContextSelect();

			case Context::USER:
			default:
				return self::getUserContextSelect();
		}
	}

	/**
	 * @throws ArgumentException
	 */
	private function getOwnerFilter(string $alias = ''): ConditionTree
	{
		$filter = Query::filter();

		if ($this->context === Context::USER)
		{
			$filter
				->logic(ConditionTree::LOGIC_AND)
				->where($alias . 'USER_ID', $this->userId)
				->where($alias . 'GROUP_ID', 0)
			;
		}
		elseif ($this->context === Context::GROUP)
		{
			$filter
				->logic(ConditionTree::LOGIC_AND)
				->where($alias . 'USER_ID', 0)
				->where($alias . 'GROUP_ID', $this->groupId)
			;
		}

		return $filter;
	}

	private static function getGroupContextSelect(): array
	{
		return ['ID', 'NAME', 'GROUP.ID', 'GROUP.NAME'];
	}

	private static function getUserContextSelect(): array
	{
		return ['ID', 'NAME', 'USER.ID', 'USER.NAME', 'USER.LAST_NAME'];
	}

	private static function getFilterContextSelect(): array
	{
		return ['ID', 'NAME'];
	}

	public function isAvailable(): bool
	{
		return $GLOBALS['USER']->isAuthorized();
	}

	public function getPreselectedItems(array $ids): array
	{
		$templateTags = array_filter($ids, fn($id) => is_string($id));

		if ($this->canPreselectTemplateTags && !empty($templateTags))
		{
			return array_map(fn($tag): Item => new Item([
				'id' => $tag,
				'entityId' => self::ENTITY_ID,
				'title' => $tag,
				'tabs' => [self::TAGS_TAB_ID],
			]), $templateTags);
		}

		return [];
	}

	public function getItems(array $ids): array
	{
		return [];
	}
}