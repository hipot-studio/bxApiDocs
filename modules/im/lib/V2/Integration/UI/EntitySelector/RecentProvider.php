<?php

namespace Bitrix\Im\V2\Integration\UI\EntitySelector;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\Model\UserTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Background\Background;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\TextField\TextFieldEnabled;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserBot;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Integration\AI\RoleManager;
use Bitrix\Im\V2\Integration\HumanResources\Department\Department;
use Bitrix\Im\V2\Integration\Socialnetwork\Group;
use Bitrix\Im\V2\Permission;
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
	private const ENTITY_TYPE_USER = 'im-user';
	private const ENTITY_TYPE_CHAT = 'im-chat';
	protected const ALLOWED_SEARCH_CHAT_TYPES = [
		Chat::IM_TYPE_CHAT,
		Chat::IM_TYPE_OPEN,
		Chat::IM_TYPE_CHANNEL,
		Chat::IM_TYPE_OPEN_CHANNEL,
		Chat::IM_TYPE_COLLAB,
		Chat::IM_TYPE_PRIVATE,
	];

	private readonly SearchOptions $searchOptions;
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

	protected function prepareOptions(array $options): array
	{
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

		return $USER->IsAuthorized();
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

		$defaultItems = $this->getDefaultDialogItems();

		rsort($defaultItems);
		$defaultItems = array_slice($defaultItems, 0, $requiredCountToFill);

		foreach ($defaultItems as $itemId)
		{
			$dialog->getRecentItems()->add(new RecentItem(['id' => $itemId, 'entityId' => static::ENTITY_ID]));
		}
	}

	protected function fillDialogByRecentDefaultItems(Dialog $dialog): void
	{
		$items = $this->getSortedLimitedBlankItems();
		$this->fillItems($items);
		$dialog->addRecentItems($items);
	}

	protected function getDefaultDialogItems(): array
	{
		if (!$this->searchOptions->shouldFillDefaultItems())
		{
			return [];
		}

		return match (true)
		{
			!$this->getContext()->getUser()->isExtranet() => Department::getInstance()->getColleagues(),
			default => Group::getUsersInSameGroups($this->getContext()->getUserId()),
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
			$chatMembers = $targetChat->getRelationsByUserIds($userIds)->getUserIds();
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

	private function getItemsWithDates(): array
	{
		$userItemsWithDate = $this->getUserItemsWithDate();
		$chatItemsWithDate = $this->getChatItemsWithDate();

		return $this->mergeByKey($userItemsWithDate, $chatItemsWithDate);
	}

	private function getSortedLimitedBlankItems(): array
	{
		$items = $this->getItemsWithDates();
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

	private function getChatItemsWithDate(): array
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

		if ($this->searchOptions->shouldFillDialogByRecent())
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
		return RelationTable::query()
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

		$query->where('PARENT_ID', 0);

		$chatTypeCondition = $this->searchOptions->getChatTypeCondition() ?? new TypeCondition(include: []);
		$query->where((new TypeFilter($chatTypeCondition, 'TYPE', 'ENTITY_TYPE'))->toConditionTree());

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

	private function getRelationFilter(): ConditionTree
	{
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
			->whereIn('TYPE', [Chat::IM_TYPE_OPEN, Chat::IM_TYPE_OPEN_CHANNEL])
		;
	}

	private function getUserItemsWithDate(): array
	{
		$result = [];
		if (!$this->searchOptions->isUserSearchEnabled())
		{
			return $result;
		}
		$recentJoinType = $this->searchOptions->shouldFillDialogByRecent() ? Join::TYPE_INNER : Join::TYPE_LEFT;
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
		elseif ($this->searchOptions->shouldFillDialogByRecent())
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
				dateLastActivity: $this->searchOptions->shouldFillDialogByRecent() ? $row['DATE_LAST_ACTIVITY'] : null,
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
			!isset($foundUserItems[$this->getContext()->getUserId()])
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

		return Helper::matchAgainstWildcard(Content::prepareStringToken($searchString));
	}
}
