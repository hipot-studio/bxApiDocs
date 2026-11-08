<?php

namespace Bitrix\Im\V2\Integration\UI\EntitySelector;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\Model\UserTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat\Access\ParentChainFilterFactory;
use Bitrix\Im\V2\Chat\Tree\TreeOrigin;
use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Background\Background;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\TextField\TextFieldEnabled;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserBot;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Folder\Definition\Selection;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\Query\FolderSearchScope;
use Bitrix\Im\V2\Folder\Query\SelectionSearchFields;
use Bitrix\Im\V2\Folder\Query\SelectionSearchFilter;
use Bitrix\Im\V2\Integration\AI\RoleManager;
use Bitrix\Im\V2\Integration\HumanResources\Department\Department;
use Bitrix\Im\V2\Integration\Socialnetwork\Group;
use Bitrix\Im\V2\Permission;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Search\Content;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserIndexTable;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\RecentItem;
use Bitrix\UI\EntitySelector\SearchQuery;

class RecentProvider extends BaseProvider
{
	use ContextCustomer;

	protected const ENTITY_ID = 'im-recent-v2';
	protected const LIMIT = 30;
	protected const TECHNICAL_LIMIT = 1000;
	public const MENTION_SEARCH_MIN_TOKEN_SIZE = 2;
	private const ENTITY_TYPE_USER = 'im-user';
	private const ENTITY_TYPE_CHAT = 'im-chat';
	private const PARENT_CHAIN_ALIAS = 'SEARCH_CHAIN';
	protected const ALLOWED_SEARCH_CHAT_TYPES = [
		Chat::IM_TYPE_CHAT,
		Chat::IM_TYPE_OPEN,
		Chat::IM_TYPE_CHANNEL,
		Chat::IM_TYPE_OPEN_CHANNEL,
		Chat::IM_TYPE_COLLAB,
		Chat::IM_TYPE_OPEN_COLLAB,
		Chat::IM_TYPE_PRIVATE,
	];

	private readonly SearchOptions $searchOptions;
	private ?FolderSearchScope $folderSearchScope = null;
	private string $preparedSearchString;
	private string $originalSearchString;
	private array $userIds;
	private array $chatIds;
	private bool $sortEnable = true;

	public function __construct(array $options = [])
	{
		$this->searchOptions = new SearchOptions($this->prepareOptions($options));

		parent::__construct();
	}

	/**
	 * The constant itself stays protected so that descendants overriding it keep working:
	 * raising its visibility would be source-incompatible with existing protected overrides.
	 *
	 * @return array<string>
	 */
	public static function getAllowedSearchChatTypes(): array
	{
		return static::ALLOWED_SEARCH_CHAT_TYPES;
	}

	protected function prepareOptions(array $options): array
	{
		if (SearchOptions::isFolderScopeSearch($options) || SearchOptions::isFolderEligibleSearch($options))
		{
			// Both folder modes own the type condition themselves and the literal whitelist cannot
			// express it: a task chat ('X') is not in ALLOWED_SEARCH_CHAT_TYPES at all. This takes nothing away
			// from a descendant with a narrower whitelist: the option is read by the default mode
			// only, while a descendant narrows itself through the options it sets on construction,
			// and those are left untouched here.
			unset($options[SearchOptions::SEARCH_CHAT_TYPES_OPTION]);

			return $options;
		}

		if (isset($options[SearchOptions::SEARCH_CHAT_TYPES_OPTION]))
		{
			$options[SearchOptions::SEARCH_CHAT_TYPES_OPTION] = $this->filterAllowedChatTypes($options[SearchOptions::SEARCH_CHAT_TYPES_OPTION]);
		}
		else
		{
			$options[SearchOptions::SEARCH_CHAT_TYPES_OPTION] = self::ALLOWED_SEARCH_CHAT_TYPES;
		}

		return $options;
	}

	protected function filterAllowedChatTypes(array $chatLiteralTypes): array
	{
		$filteredChatLiteralTypes = [];

		foreach ($chatLiteralTypes as $chatLiteralType)
		{
			if (in_array($chatLiteralType, self::ALLOWED_SEARCH_CHAT_TYPES, true))
			{
				$filteredChatLiteralTypes[] = $chatLiteralType;
			}
		}

		return $filteredChatLiteralTypes;
	}

	public function isAvailable(): bool
	{
		global $USER;

		if (!$USER->IsAuthorized())
		{
			return false;
		}

		// The only gate of the folder mode: this path skips the prefilters of the module, and
		// Entity::create() asks the provider before anything else - so one check closes both the
		// search and the filling of the list before the first keystroke.
		if ($this->searchOptions->isFolderScopeMode())
		{
			return $this->resolveFolderScopeSelection() !== null;
		}

		return true;
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$this->originalSearchString = $searchQuery->getQuery();
		$this->preparedSearchString = $this->prepareSearchString($searchQuery->getQuery());
		$searchQuery->setCacheable(false);
		if (!Content::canUseFulltextSearch($this->preparedSearchString))
		{
			return;
		}

		$items = $this->getSortedLimitedBlankItems();
		$this->fillItems($items);
		$dialog->addItems($items);
	}

	public function fillDialog(Dialog $dialog): void
	{
		if (!Loader::includeModule('intranet'))
		{
			return;
		}

		if ($this->searchOptions->shouldFillDialogByRecent())
		{
			$this->fillDialogByRecentDefaultItems($dialog);
		}
		else
		{
			$this->fillDialogByDefaultItems($dialog);
		}
	}

	protected function fillDialogByDefaultItems(Dialog $dialog): void
	{
		$requiredCountToFill = self::LIMIT - $dialog->getRecentItems()->count();
		if ($requiredCountToFill <= 0)
		{
			return;
		}

		$defaultItems = $this->getDefaultDialogItems($requiredCountToFill);

		rsort($defaultItems);
		$defaultItems = array_slice($defaultItems, 0, $requiredCountToFill);

		foreach ($defaultItems as $itemId)
		{
			$dialog->getRecentItems()->add(new RecentItem(['id' => $itemId, 'entityId' => static::ENTITY_ID]));
		}
	}

	protected function fillDialogByRecentDefaultItems(Dialog $dialog): void
	{
		$items = $this->getSortedLimitedBlankItems(recentOnly: true);
		$this->fillItems($items);
		$dialog->addRecentItems($items);
	}

	protected function getDefaultDialogItems(int $limit = self::LIMIT): array
	{
		if (!$this->searchOptions->shouldFillDefaultItems())
		{
			return [];
		}

		// Default items come from department/workgroup helpers and bypass getIntranetFilter,
		// so the guest visibility scope would not apply here — gate guests to an empty default.
		if ($this->getContext()->getUser() instanceof UserGuest)
		{
			return [];
		}

		$userId = $this->getContext()->getUserId();

		return match (true)
		{
			!$this->getContext()->getUser()->isExtranet()
				=> Department::getInstance()->getColleagueIdsByUserId($userId, $limit),
			default
				=> Group::getUsersInSameGroups($userId),
		};
	}

	public function getItems(array $ids): array
	{
		$this->sortEnable = false;
		$this->setUserAndChatIds($ids);
		$items = $this->getItemsWithDates();
		$this->fillItems($items);

		$this->chatIds = [];
		$this->userIds = [];

		return $items;
	}

	public function getPreselectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	private function setUserAndChatIds(array $ids): void
	{
		foreach ($ids as $id)
		{
			if ($this->isChatId($id) && $this->searchOptions->isChatSearchEnabled())
			{
				$chatId = substr($id, 4);
				$this->chatIds[$chatId] = $chatId;
			}
			elseif ($this->searchOptions->isUserSearchEnabled())
			{
				$this->userIds[$id] = $id;
			}
		}
	}

	private function getBlankItem(
		string $dialogId,
		?DateTime $dateMessage = null,
		?DateTime $secondDate = null,
		?DateTime $dateLastActivity = null
	): Item
	{
		$id = $dialogId;
		$entityType = self::ENTITY_TYPE_USER;
		if ($this->isChatId($dialogId))
		{
			$id = substr($dialogId, 4);
			$entityType = self::ENTITY_TYPE_CHAT;
		}
		$customData = ['id' => $id];
		$sort = 0;
		$customData['dateMessage'] = $dateMessage;
		$customData['dateLastActivity'] = $dateLastActivity;
		$customData['secondSort'] = $secondDate instanceof DateTime ? $secondDate->getTimestamp() : 0;
		if ($this->sortEnable)
		{
			$sort = match (true)
			{
				isset($dateLastActivity) => $dateLastActivity->getTimestamp(),
				isset($dateMessage) => $dateMessage->getTimestamp(),
				default => 0,
			};
		}

		return new Item([
			'id' => $dialogId,
			'entityId' => static::ENTITY_ID,
			'entityType' => $entityType,
			'sort' => $sort,
			'customData' => $customData,
		]);
	}

	/**
	 * @param Item[] $items
	 * @return array
	 */
	private function fillItems(array $items): void
	{
		$userIds = [];
		$chats = [];
		foreach ($items as $item)
		{
			$id = $item->getCustomData()->get('id');
			if ($item->getEntityType() === self::ENTITY_TYPE_USER)
			{
				$userIds[] = $id;

				continue;
			}

			$chats[$id] = Chat::getInstance($id);
		}

		$users = new UserCollection($userIds);
		$users->fillOnlineData();
		$privateChatIds = \Bitrix\Im\Dialog::getChatIds($userIds, $this->getContext()->getUserId());
		$copilotRoles = $this->getCopilotRoles($this->filterCopilotChats($chats));
		Chat::fillSelfRelations($chats);

		$chatMembers = [];
		if ($this->searchOptions->isChatContext())
		{
			$targetChat = Chat::getInstance($this->searchOptions->getContextChatId());
			$chatMembers = $targetChat->getRelationsByUserIds($userIds)->filterActiveMembers()->getUserIds();
		}

		foreach ($items as $item)
		{
			$customData = $item->getCustomData()->getValues();
			if ($item->getEntityType() === self::ENTITY_TYPE_USER)
			{
				$user = $users->getById($customData['id']);
				$customData['user'] = $user->toRestFormat();

				$chatId = (int)$privateChatIds[$customData['id']];
				$customData['chat']['textFieldEnabled'] = (new TextFieldEnabled($chatId))->get();
				$customData['chat']['backgroundId'] = (new Background($chatId))->get();
				$customData['copilot'] = null;

				$customData['isContextChatMember'] = $this->searchOptions->isChatContext() ? in_array($user->getId(), $chatMembers, true) : null;

				$item
					->setTitle($user->getName())
					->setAvatar($user->getAvatar())
					->setCustomData($customData)
				;
			}
			if ($item->getEntityType() === self::ENTITY_TYPE_CHAT)
			{
				$chat = $chats[$customData['id']] ?? null;
				if ($chat === null)
				{
					continue;
				}

				$customData['chat'] = $chat->toRestFormat(['CHAT_SHORT_FORMAT' => true]);
				$customData['copilot'] = $copilotRoles[$chat->getId()] ?? null;
				$item
					->setTitle($chat->getTitle())
					->setAvatar($chat->getAvatar())
					->setCustomData($customData)
				;
			}
		}
	}

	/**
	 * @param Chat\CopilotChat[] $copilotChats
	 * @return array
	 */
	private function getCopilotRoles(array $copilotChats): array
	{
		$roleManager = new RoleManager();

		$roleCodes = [];
		foreach ($copilotChats as $chat)
		{
			$chatId = (int)$chat->getId();
			$roleCodes[$chatId] = $roleManager->getMainRole($chatId);
		}

		$roles = $roleManager->getRoles($roleCodes);

		$result = [];
		foreach ($roleCodes as $chatId => $code)
		{
			$result[$chatId] = $roles[$code] ?? $roles[RoleManager::getDefaultRoleCode()];
		}

		return $result;
	}

	/**
	 * @param Chat[] $chats
	 * @return Chat\CopilotChat[]
	 */
	private function filterCopilotChats(array $chats): array
	{
		return array_filter($chats, static fn($chat) => $chat instanceof Chat\CopilotChat);
	}

	private function getItemsWithDates(bool $recentOnly = false): array
	{
		$userItemsWithDate = $this->getUserItemsWithDate($recentOnly);
		$chatItemsWithDate = $this->getChatItemsWithDate($recentOnly);

		return $this->mergeByKey($userItemsWithDate, $chatItemsWithDate);
	}

	private function getSortedLimitedBlankItems(bool $recentOnly = false): array
	{
		$items = $this->getItemsWithDates($recentOnly);
		usort($items, function(Item $a, Item $b) {
			if ($b->getSort() === $a->getSort())
			{
				if (!$this->isChatId($b->getId()) && !$this->isChatId($a->getId()))
				{
					$bUser = User::getInstance($b->getId());
					$aUser = User::getInstance($a->getId());
					if ($aUser->isExtranet() === $bUser->isExtranet())
					{
						return $bUser->getId() <=> $aUser->getId();
					}

					return $aUser->isExtranet() <=> $bUser->isExtranet();
				}
				return (int)$b->getCustomData()->get('secondSort') <=> (int)$a->getCustomData()->get('secondSort');
			}
			return $b->getSort() <=> $a->getSort();
		});

		return array_slice($items, 0, self::LIMIT);
	}

	private function getChatItemsWithDate(bool $recentOnly = false): array
	{
		if (!$this->searchOptions->isChatSearchEnabled())
		{
			return [];
		}

		if (isset($this->preparedSearchString))
		{
			return $this->mergeByKey(
				$this->getChatItemsWithDateByUsers(),
				$this->getChatItemsWithDateByTitle()
			);
		}

		if (isset($this->chatIds) && !empty($this->chatIds))
		{
			return $this->getChatItemsWithDateByIds();
		}

		if ($recentOnly)
		{
			return $this->getRecentChatItemsWithOrder();
		}

		return [];
	}

	private function getRecentChatItemsWithOrder(): array
	{
		if (!$this->searchOptions->shouldFillDialogByRecent())
		{
			return [];
		}

		$result = $this
			->getCommonChatQuery()
			->addSelect('RECENT.DATE_LAST_ACTIVITY', 'DATE_LAST_ACTIVITY')
			->registerRuntimeField(
				new Reference(
					'RECENT',
					RecentTable::class,
					Join::on('this.ID', 'ref.ITEM_CID')
						->where('ref.USER_ID', $this->getContext()->getUserId()),
					['join_type' => Join::TYPE_INNER]
				)
			)
			->setOrder(['RECENT.DATE_LAST_ACTIVITY' => 'DESC'])
			->fetchAll()
		;

		return $this->getChatItemsByRawResult($result);
	}

	private function getChatItemsWithDateByIds(): array
	{
		if (!isset($this->chatIds) || empty($this->chatIds))
		{
			return [];
		}

		$result = $this->getCommonChatQuery(limit: self::TECHNICAL_LIMIT)->whereIn('ID', $this->chatIds)->fetchAll();

		return $this->getChatItemsByRawResult($result);
	}

	private function getChatItemsWithDateByTitle(): array
	{
		if (!isset($this->preparedSearchString))
		{
			return [];
		}

		$result = $this
			->getCommonChatQueryWithOrder()
			->whereMatch('INDEX.SEARCH_TITLE', $this->preparedSearchString)
			->fetchAll()
		;

		return $this->getChatItemsByRawResult($result, ['byUser' => false]);
	}

	private function getChatItemsWithDateByUsers(): array
	{
		if (!isset($this->preparedSearchString) || !$this->searchOptions->shouldSearchChatsByUsers())
		{
			return [];
		}

		$result = $this
			->getCommonChatQueryWithOrder(Join::TYPE_INNER)
			->registerRuntimeField(
				'CHAT_SEARCH',
				(new Reference(
					'CHAT_SEARCH',
					Entity::getInstanceByQuery($this->getChatsByUserNameQuery()),
					Join::on('this.ID', 'ref.CHAT_ID')
				))->configureJoinType(Join::TYPE_INNER)
			)
			->fetchAll()
		;

		return $this->getChatItemsByRawResult($result, ['byUser' => true]);
	}

	private function getChatsByUserNameQuery(): Query
	{
		$query = RelationTable::query()
			->setSelect(['CHAT_ID'])
			->registerRuntimeField(
				'USER',
				(new Reference(
					'USER',
					\Bitrix\Main\UserTable::class,
					Join::on('this.USER_ID', 'ref.ID'),
				))->configureJoinType(Join::TYPE_INNER)
			)
			->registerRuntimeField(
				'USER_INDEX',
				(new Reference(
					'USER_INDEX',
					UserIndexTable::class,
					Join::on('this.USER_ID', 'ref.USER_ID'),
				))->configureJoinType(Join::TYPE_INNER)
			)
			->whereIn('MESSAGE_TYPE', [Chat::IM_TYPE_CHAT, Chat::IM_TYPE_OPEN])
			->where('USER.IS_REAL_USER', 'Y')
			->whereMatch('USER_INDEX.SEARCH_USER_CONTENT', $this->preparedSearchString)
			->setGroup(['CHAT_ID'])
		;

		ServiceLocator::getInstance()->get(ParentChainFilterFactory::class)
			->forUser($this->getContext()->getUserId(), TreeOrigin::forChat('CHAT'))
			->apply($query);

		return $query;
	}

	protected function getChatItemsByRawResult(array $raw, array $additionalCustomData = []): array
	{
		$result = [];

		foreach ($raw as $row)
		{
			$dialogId = 'chat' . $row['ID'];
			$dateLastActivity = $row['DATE_LAST_ACTIVITY'] ?? null;
			$messageDate = $row['MESSAGE_DATE_CREATE'] ?? null;
			$secondDate = $row['MESSAGE_DATE_CREATE'] ?? null;
			if (($row['IS_MEMBER'] ?? 'Y') === 'N')
			{
				$messageDate = null;
				$dateLastActivity = null;
			}
			$item = $this->getBlankItem(
				dialogId: $dialogId,
				dateMessage: $messageDate,
				secondDate: $secondDate,
				dateLastActivity: $dateLastActivity,
			);
			if (!empty($additionalCustomData))
			{
				$customData = $item->getCustomData()->getValues();
				$item->setCustomData(array_merge($customData, $additionalCustomData));
			}
			$result[$dialogId] = $item;
		}

		return $result;
	}

	protected function getCommonChatQueryWithOrder(string $joinType = Join::TYPE_LEFT, int $limit = self::LIMIT): Query
	{
		return $this->getCommonChatQuery($joinType, $limit)
			->setOrder(['IS_MEMBER' => 'DESC', 'LAST_MESSAGE_ID' => 'DESC', 'DATE_CREATE' => 'DESC'])
		;
	}

	protected function getCommonChatQuery(string $joinType = Join::TYPE_LEFT, int $limit = self::LIMIT): Query
	{
		$query = ChatTable::query()
			->setSelect(['ID', 'IS_MEMBER', 'MESSAGE_DATE_CREATE' => 'MESSAGE.DATE_CREATE', 'DATE_CREATE'])
			->registerRuntimeField(new Reference(
					'RELATION',
					RelationTable::class,
					Join::on('this.ID', 'ref.CHAT_ID')
						->where('ref.USER_ID', $this->getContext()->getUserId()),
					['join_type' => $joinType]
				)
			)
			->registerRuntimeField(
				new Reference(
					'MESSAGE',
					MessageTable::class,
					Join::on('this.LAST_MESSAGE_ID', 'ref.ID'),
					['join_type' => Join::TYPE_LEFT]
				)
			)
			->registerRuntimeField(
				'IS_MEMBER',
				(new ExpressionField(
					'IS_MEMBER',
					"CASE WHEN (%s IS NULL OR %s = 'Y') THEN 'N' ELSE 'Y' END",
					['RELATION.ID', 'RELATION.IS_HIDDEN']
				))->configureValueType(BooleanField::class)
			)
			->whereNot('TYPE', Chat::IM_TYPE_PRIVATE)
			->setLimit($limit)
		;

		if ($joinType === Join::TYPE_LEFT)
		{
			$query->where($this->getRelationFilter());
		}

		if ($this->searchOptions->shouldFilterByManageMessagesRight())
		{
			Permission\Filter::getRoleOrmFilter($query, Permission\ActionGroup::ManageMessages, 'RELATION', '');
		}

		if ($this->searchOptions->shouldFilterByManageUsersAddRight())
		{
			Permission\Filter::getRoleOrmFilter($query, Permission\ActionGroup::ManageUsersAdd, 'RELATION', '');
		}

		if ($this->searchOptions->shouldFilterByOwnerRight())
		{
			$query->where('AUTHOR_ID', $this->getContext()->getUserId());
		}

		if ($this->searchOptions->shouldExcludeIds())
		{
			$query->whereNotIn('ID', $this->searchOptions->getExcludeIds());
		}

		if ($this->searchOptions->isRecentSectionMode() || $this->searchOptions->getParentId() !== null)
		{
			$this->applyRecentSectionScope($query);
		}

		// Root section search already excludes nested chats, so ancestor-chain access
		// checks would only add redundant joins without changing the result set.
		if ($this->shouldApplyParentChainFilter())
		{
			ServiceLocator::getInstance()->get(ParentChainFilterFactory::class)
				->forUser($this->getContext()->getUserId(), TreeOrigin::forChat())
				->apply($query);
		}

		if ($this->searchOptions->isFolderScopeMode())
		{
			$query->where($this->getFolderScopeFilter()->toConditionTree());
			$this->applyFolderScopeParentChainFilter($query);
		}
		else
		{
			$chatTypeCondition = $this->searchOptions->getChatTypeCondition() ?? new TypeCondition(include: []);
			$query->where((new TypeFilter($chatTypeCondition, 'TYPE', 'ENTITY_TYPE'))->toConditionTree());
		}

		if ($this->searchOptions->shouldFilterByNullEntityType())
		{
			$query->where(Query::filter()
				->logic('or')
				->whereNull('ENTITY_TYPE')
				->where('ENTITY_TYPE', ''))
			;
		}

		return $query;
	}

	private function applyRecentSectionScope(Query $query): void
	{
		$parentId = $this->searchOptions->getParentId();
		if ($parentId === 0)
		{
			$query->where(
				Query::filter()
					->logic('or')
					->whereNull('PARENT_ID')
					->where('PARENT_ID', 0)
			);
		}
		elseif ($parentId !== null)
		{
			$query->where('PARENT_ID', $parentId);
		}
	}

	private function shouldApplyParentChainFilter(): bool
	{
		// folder mode checks the chain on its own, see applyFolderScopeParentChainFilter()
		if ($this->searchOptions->isFolderScopeMode())
		{
			return false;
		}

		return $this->searchOptions->getParentId() !== 0;
	}

	/**
	 * In folder mode a branch reaching nested chats shares the query with branches framed to the
	 * root, so the chain is not joined to every row: a root chat passes on its PARENT_ID alone,
	 * and only a nested one is checked, by a subquery correlated on the chat id.
	 *
	 * The subquery runs for every matching nested chat of such a branch, before the membership
	 * filter: it pays off while those are few among the matches, and the joins would cost less
	 * only if nested chats dominated them.
	 */
	private function applyFolderScopeParentChainFilter(Query $query): void
	{
		// while every branch is framed to the root, there is no nested chat to check
		if (!$this->getFolderScopeFilter()->containsNestedChats())
		{
			return;
		}

		$chainQuery = ChatTable::query()
			->setCustomBaseTableAlias(self::PARENT_CHAIN_ALIAS)
			->setSelect([new ExpressionField('CHAIN_ACCESSIBLE', '1')])
			->where('ID', new SqlExpression('%s'))
		;
		ServiceLocator::getInstance()->get(ParentChainFilterFactory::class)
			->forUser($this->getContext()->getUserId(), TreeOrigin::forChat())
			->apply($chainQuery);

		$query->where(
			Query::filter()
				->logic('or')
				->whereNull('PARENT_ID')
				->where('PARENT_ID', 0)
				->whereExpr("EXISTS ({$chainQuery->getQuery()})", ['ID'])
		);
	}

	/**
	 * An unavailable scope expands into nothing, and the translator writes that as a false
	 * condition. In production this is unreachable - {@see self::isAvailable()} closes the path
	 * before the query is built - but it keeps a direct call to the provider from widening the
	 * result set instead of emptying it.
	 */
	private function getFolderScopeFilter(): SelectionSearchFilter
	{
		return new SelectionSearchFilter(
			$this->resolveFolderScopeSelection() ?? new Selection(),
			new SelectionSearchFields(
				typeField: 'TYPE',
				entityTypeField: 'ENTITY_TYPE',
				chatIdField: 'ID',
				parentField: 'PARENT_ID',
			),
		);
	}

	private function resolveFolderScopeSelection(): ?Selection
	{
		$this->folderSearchScope ??= new FolderSearchScope(
			ServiceLocator::getInstance()->get(FolderProvider::class)
		);

		return $this->folderSearchScope->resolve(
			$this->searchOptions->getFolderScopeId(),
			$this->getContext()->getUserId(),
		);
	}

	private function getRelationFilter(): ConditionTree
	{
		// A personal folder holds only chats the user is in, so an open chat is not offered for
		// discovery here. A hidden relation is membership too - unlike IS_MEMBER, which counts it as
		// a non-member to hide the dates of such a chat.
		if ($this->searchOptions->isFolderEligibleMode())
		{
			return Query::filter()->whereNotNull('RELATION.USER_ID');
		}

		$relationFilter = Query::filter()
			->whereNotNull('RELATION.USER_ID')
			->where('RELATION.IS_HIDDEN', 'N')
		;

		if (User::getCurrent()->isExtranet())
		{
			return $relationFilter;
		}

		return Query::filter()
			->logic('or')
			->where($relationFilter)
			->whereIn('TYPE', [Chat::IM_TYPE_OPEN, Chat::IM_TYPE_OPEN_CHANNEL, Chat::IM_TYPE_OPEN_COLLAB])
		;
	}

	private function getUserItemsWithDate(bool $recentOnly = false): array
	{
		$result = [];
		if (!$this->searchOptions->isUserSearchEnabled())
		{
			return $result;
		}
		$recentJoinType = $recentOnly ? Join::TYPE_INNER : Join::TYPE_LEFT;
		$query = UserTable::query()
			->setSelect([
				'ID',
				'DATE_MESSAGE' => 'RECENT.DATE_MESSAGE',
				'IS_INTRANET_USER',
				'DATE_CREATE' => 'DATE_REGISTER',
				'DATE_LAST_ACTIVITY' => 'RECENT.DATE_LAST_ACTIVITY',
			])
			->where('ACTIVE', true)
			->registerRuntimeField(
				'RECENT',
				new Reference(
					'RECENT',
					RecentTable::class,
					Join::on('this.ID', 'ref.ITEM_ID')
						->where('ref.USER_ID', $this->getContext()->getUserId())
						->where('ref.ITEM_TYPE', Chat::IM_TYPE_PRIVATE),
					['join_type' => $recentJoinType]
				)
			)
		;

		if (isset($this->preparedSearchString))
		{
			$query
				->whereMatch('INDEX.SEARCH_USER_CONTENT', $this->preparedSearchString)
				->setOrder(['RECENT.DATE_MESSAGE' => 'DESC', 'IS_INTRANET_USER' => 'DESC', 'DATE_CREATE' => 'DESC'])
				->setLimit(self::LIMIT)
			;
		}
		elseif (isset($this->userIds) && !empty($this->userIds))
		{
			$query->whereIn('ID', $this->userIds)->setLimit(self::TECHNICAL_LIMIT);
		}
		elseif ($recentOnly)
		{
			$query
				->setOrder(['RECENT.DATE_LAST_ACTIVITY' => 'DESC', 'IS_INTRANET_USER' => 'DESC', 'DATE_CREATE' => 'DESC'])
				->setLimit(self::LIMIT)
			;
		}
		else
		{
			return [];
		}

		$query->where($this->getIntranetFilter());

		if ($this->searchOptions->shouldExcludeCurrentUser())
		{
			$query->whereNot('ID', $this->getContext()->getUserId());
		}

		if ($this->searchOptions->shouldExcludeGuests())
		{
			$query->where(
				Query::filter()
					->logic('or')
					->whereNot('EXTERNAL_AUTH_ID', UserGuest::AUTH_ID)
					->whereNull('EXTERNAL_AUTH_ID')
			);
		}

		$raw = $query->fetchAll();

		foreach ($raw as $row)
		{
			if ($this->isHiddenBot((int)$row['ID']))
			{
				continue;
			}

			$result[(int)$row['ID']] = $this->getBlankItem(
				dialogId: (int)$row['ID'],
				dateMessage: $row['DATE_MESSAGE'],
				secondDate: $row['DATE_CREATE'],
				dateLastActivity: $recentOnly ? $row['DATE_LAST_ACTIVITY'] : null,
			);
		}

		$result = $this->getAdditionalUsers($result);

		return $result;
	}

	private function getAdditionalUsers(array $foundUserItems): array
	{
		if ($this->needAddFavoriteChat($foundUserItems))
		{
			$foundUserItems[$this->getContext()->getUserId()] = $this->getFavoriteChatUserItem();
		}

		return $foundUserItems;
	}

	private function getFavoriteChatUserItem(): Item
	{
		$userId = $this->getContext()->getUserId();
		$row = ChatTable::query()
			->setSelect(['DATE_MESSAGE' => 'MESSAGE.DATE_CREATE', 'DATE_CREATE'])
			->registerRuntimeField(
				new Reference(
					'MESSAGE',
					MessageTable::class,
					Join::on('this.LAST_MESSAGE_ID', 'ref.ID'),
					['join_type' => Join::TYPE_LEFT]
				)
			)
			->where('ENTITY_TYPE', Chat::ENTITY_TYPE_FAVORITE)
			->where('ENTITY_ID', $userId)
			->fetch() ?: []
		;
		$dateMessage = $row['DATE_MESSAGE'] ?? null;
		$dateCreate = $row['DATE_CREATE'] ?? null;

		return $this->getBlankItem(
			dialogId: $this->getContext()->getUserId(),
			dateMessage: $dateMessage,
			secondDate: $dateCreate,
		);
	}

	private function needAddFavoriteChat(array $foundUserItems): bool
	{
		return
			!$this->searchOptions->shouldExcludeCurrentUser()
			&& !isset($foundUserItems[$this->getContext()->getUserId()])
			&& isset($this->originalSearchString)
			&& static::isPhraseFoundBySearchQuery(Chat\FavoriteChat::getTitlePhrase(), $this->originalSearchString)
		;
	}

	private static function isPhraseFoundBySearchQuery(string $phrase, string $searchQuery): bool
	{
		$searchWords = explode(' ', $searchQuery);
		$phraseWords = explode(' ', $phrase);

		foreach ($searchWords as $searchWord)
		{
			$searchWordLowerCase = mb_strtolower($searchWord);
			$found = false;
			foreach ($phraseWords as $phraseWord)
			{
				$phraseWordLowerCase = mb_strtolower($phraseWord);
				if (str_starts_with($phraseWordLowerCase, $searchWordLowerCase))
				{
					$found = true;
					break;
				}
			}
			if (!$found)
			{
				return false;
			}
		}

		return true;
	}

	private function isHiddenBot(int $userId): bool
	{
		$user = User::getInstance($userId);

		if ($user instanceof UserBot && $user->isBot())
		{
			if (!$this->searchOptions->isBotSearchEnabled())
			{
				return true;
			}

			return $user->isHidden();
		}

		return false;
	}

	private function getIntranetFilter(): ConditionTree
	{
		$filter = Query::filter();

		$currentUser = User::getCurrent();
		if ($currentUser instanceof UserGuest)
		{
			return $filter->whereIn('ID', $currentUser->getCommonChatPartnersQuery());
		}

		if (!Loader::includeModule('intranet'))
		{
			return $filter->where($this->getRealUserOrBotCondition());
		}

		$subQuery = Group::getExtranetAccessibleUsersQuery($this->getContext()->getUserId());
		if (!User::getCurrent()->isExtranet())
		{
			$filter->logic('or');
			$filter->where('IS_INTRANET_USER', true);
			if ($subQuery !== null)
			{
				$filter->whereIn('ID', $subQuery);
			}
			return $filter;
		}

		$filter->where($this->getRealUserOrBotCondition());
		if ($subQuery !== null)
		{
			$filter->whereIn('ID', $subQuery);
		}
		else
		{
			$filter->where(new ExpressionField('EMPTY_LIST', '1'), '!=', 1);
		}

		return $filter;
	}

	private function getRealUserOrBotCondition(): ConditionTree
	{
		return Query::filter()
			->logic('or')
			->whereNotIn('EXTERNAL_AUTH_ID', UserTable::filterExternalUserTypes(['bot']))
			->whereNull('EXTERNAL_AUTH_ID')
		;
	}

	private function mergeByKey(array ...$arrays): array
	{
		$result = [];
		foreach ($arrays as $array)
		{
			foreach ($array as $key => $value)
			{
				$result[$key] = $value;
			}
		}

		return $result;
	}

	private function isChatId(string $id): bool
	{
		return substr($id, 0, 4) === 'chat';
	}

	private function prepareSearchString(string $searchString): string
	{
		$searchString = trim($searchString);
		$token = Content::prepareStringToken($searchString);

		if ($this->searchOptions->isMentionSearch())
		{
			return Helper::matchAgainstWildcard($token, '*', self::getMentionSearchMinTokenSize());
		}

		return Helper::matchAgainstWildcard($token);
	}

	/**
	 * The mention list searches from a shorter query than the rest of the provider consumers: a wildcard
	 * matches the beginning of a word already in the index, so a query shorter than the minimal indexed
	 * word is allowed - a two-letter prefix still finds a longer indexed name. The index setting stays
	 * the lower bound: a portal that indexes even shorter words keeps its own value.
	 */
	public static function getMentionSearchMinTokenSize(): int
	{
		$indexMinTokenSize = Helper::getMinTokenSize();

		if (!Features::isMentionInstantSearchAvailable())
		{
			return $indexMinTokenSize;
		}

		return min(self::MENTION_SEARCH_MIN_TOKEN_SIZE, $indexMinTokenSize);
	}
}
