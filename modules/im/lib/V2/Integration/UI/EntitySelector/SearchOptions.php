<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\UI\EntitySelector;

use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Folder\Eligibility\EligibleChatTypes;
use Bitrix\Im\V2\Integration\UI\EntitySelector\SearchOptions\FlagOption;
use Bitrix\Im\V2\Integration\UI\EntitySelector\SearchOptions\SearchingFlag;
use Bitrix\Im\V2\Recent\Config\RecentConfigManager;
use Bitrix\Main\DI\ServiceLocator;

class SearchOptions
{
	// Mode
	public const SEARCH_FOLDER_ID_OPTION = 'searchFolderId';
	public const SEARCH_RECENT_SECTION_OPTION = 'searchRecentSection';
	public const SEARCH_FOLDER_ELIGIBLE_OPTION = 'searchFolderEligible';
	public const INCLUDE_ONLY_OPTION = 'includeOnly';
	public const EXCLUDE_OPTION = 'exclude';
	public const SEARCH_CHAT_TYPES_OPTION = 'searchChatTypes';
	public const EXCLUDE_CHAT_TYPES_OPTION = 'excludeChatTypes';
	public const SEARCH_ALL_CHATS_OPTION = 'searchAllChats';

	// Filters
	public const FILL_DIALOG_BY_RECENT_OPTION = 'fillDialogByRecent';
	public const PARENT_ID_OPTION = 'parentId';

	public const WITH_CHAT_BY_USERS_OPTION = 'withChatByUsers';
	public const ONLY_WITH_MANAGE_MESSAGES_RIGHT_OPTION = 'onlyWithManageMessagesRight';
	public const ONLY_WITH_MANAGE_USERS_ADD_RIGHT_OPTION = 'onlyWithManageUsersAddRight';
	public const ONLY_WITH_OWNER_RIGHT_OPTION = 'onlyWithOwnerRight';
	public const ONLY_WITH_NULL_ENTITY_TYPE_OPTION = 'onlyWithNullEntityType';
	public const EXCLUDE_IDS_OPTION = 'excludeIds';
	public const CONTEXT_CHAT_ID = 'contextChatId';
	public const EXCLUDE_GUESTS_OPTION = 'excludeGuests';
	public const EXCLUDE_CURRENT_USER_OPTION = 'excludeCurrentUser';
	public const MENTION_SEARCH_OPTION = 'mentionSearch';

	// Resolved state
	private FlagOption $flagOption;
	private ?TypeCondition $chatTypeCondition = null;
	private bool $isRecentSectionMode = false;
	private bool $isFolderEligibleMode = false;
	private bool $isFolderScopeMode = false;
	private int $folderScopeId = 0;
	private bool $shouldToFillDefaultItems = true;
	private ?int $contextChatId = null;

	// Filter options state
	private bool $withChatByUsers = false;
	private bool $onlyWithManageMessagesRight = false;
	private bool $onlyWithManageUsersAddRight = false;
	private bool $onlyWithOwnerRight = false;
	private bool $onlyWithNullEntityType = false;
	private bool $fillDialogByRecent = false;
	private bool $excludeGuests = false;
	private bool $excludeCurrentUser = false;
	private bool $mentionSearch = false;
	private array $excludeIds = [];
	private ?int $parentId = null;

	private readonly TypeRegistry $typeRegistry;
	private readonly SearchAllChatsTypeConditionProvider $searchAllChatsTypeConditionProvider;

	public function __construct(array $rawOptions)
	{
		$this->typeRegistry = ServiceLocator::getInstance()->get(TypeRegistry::class);
		$this->searchAllChatsTypeConditionProvider =
			ServiceLocator::getInstance()
				->get(SearchAllChatsTypeConditionProvider::class)
		;
		$this->setDefaultState();
		$this->resolve($rawOptions);
	}

	/**
	 * Composition of a personal folder: the chat type condition comes from the folder rule
	 * ({@see EligibleChatTypes}) and wins over {@see self::SEARCH_RECENT_SECTION_OPTION} and
	 * {@see self::SEARCH_CHAT_TYPES_OPTION}. The list of allowed types stays on the server -
	 * the client only asks for the mode.
	 */
	public static function isFolderEligibleSearch(array $rawOptions): bool
	{
		return ($rawOptions[self::SEARCH_FOLDER_ELIGIBLE_OPTION] ?? false) === true;
	}

	/**
	 * Search scoped to the composition of a folder: the client asks for the mode by the folder
	 * id alone, and the server owns the types, the frames and the flags of the branches. Wins
	 * over every other mode - the folder describes the result set on its own, so a section, a
	 * type list or the all-chats mode on top of it could only contradict it.
	 */
	public static function isFolderScopeSearch(array $rawOptions): bool
	{
		return isset($rawOptions[self::SEARCH_FOLDER_ID_OPTION]);
	}

	public function isUserSearchEnabled(): bool
	{
		if ($this->isNestedRecentSectionSearch())
		{
			return false;
		}

		return $this->flagOption->isFlagEnabled(SearchingFlag::Users);
	}

	public function isChatSearchEnabled(): bool
	{
		return $this->flagOption->isFlagEnabled(SearchingFlag::Chats);
	}

	public function isBotSearchEnabled(): bool
	{
		return $this->flagOption->isFlagEnabled(SearchingFlag::Bots);
	}

	public function getChatTypeCondition(): ?TypeCondition
	{
		return $this->chatTypeCondition;
	}

	public function shouldSearchChatsByUsers(): bool
	{
		return $this->withChatByUsers;
	}

	public function shouldFilterByManageMessagesRight(): bool
	{
		if (!$this->isChatSearchEnabled())
		{
			return false;
		}
		return $this->onlyWithManageMessagesRight;
	}

	public function shouldFilterByManageUsersAddRight(): bool
	{
		if (!$this->isChatSearchEnabled())
		{
			return false;
		}
		return $this->onlyWithManageUsersAddRight;
	}

	public function shouldFilterByOwnerRight(): bool
	{
		if (!$this->isChatSearchEnabled())
		{
			return false;
		}
		return $this->onlyWithOwnerRight;
	}

	public function shouldFilterByNullEntityType(): bool
	{
		if (!$this->isChatSearchEnabled())
		{
			return false;
		}
		return $this->onlyWithNullEntityType;
	}

	public function getExcludeIds(): array
	{
		return $this->excludeIds;
	}

	public function shouldExcludeIds(): bool
	{
		return !empty($this->excludeIds);
	}

	public function isRecentSectionMode(): bool
	{
		return $this->isRecentSectionMode;
	}

	public function isFolderEligibleMode(): bool
	{
		return $this->isFolderEligibleMode;
	}

	public function isFolderScopeMode(): bool
	{
		return $this->isFolderScopeMode;
	}

	public function getFolderScopeId(): int
	{
		return $this->folderScopeId;
	}

	public function shouldFillDefaultItems(): bool
	{
		return $this->shouldToFillDefaultItems;
	}

	public function getParentId(): ?int
	{
		return $this->parentId;
	}

	public function isChatContext(): bool
	{
		return isset($this->contextChatId);
	}

	public function getContextChatId(): ?int
	{
		return $this->contextChatId;
	}

	public function shouldFillDialogByRecent(): bool
	{
		return $this->fillDialogByRecent;
	}

	public function shouldExcludeGuests(): bool
	{
		return $this->excludeGuests;
	}

	public function shouldExcludeCurrentUser(): bool
	{
		return $this->excludeCurrentUser;
	}

	/**
	 * Set by the mention list only: it is the single consumer allowed to search from a shorter query.
	 * Kept apart from the chat context: that one marks participants of the chat and is read elsewhere.
	 */
	public function isMentionSearch(): bool
	{
		return $this->mentionSearch;
	}

	private function resolve(array $rawOptions): void
	{
		$isSearchAllChatsEnabled =
			isset($rawOptions[self::SEARCH_ALL_CHATS_OPTION])
			&& $rawOptions[self::SEARCH_ALL_CHATS_OPTION] === true
		;
		$isRecentSectionSearchEnabled =
			isset($rawOptions[self::SEARCH_RECENT_SECTION_OPTION])
			&& is_string($rawOptions[self::SEARCH_RECENT_SECTION_OPTION])
		;
		$needToFillDialogByRecent =
			isset($rawOptions[self::FILL_DIALOG_BY_RECENT_OPTION])
			&& $rawOptions[self::FILL_DIALOG_BY_RECENT_OPTION] === true
		;

		match (true)
		{
			self::isFolderScopeSearch($rawOptions) => $this->resolveAsFolderScopeMode($rawOptions),
			self::isFolderEligibleSearch($rawOptions) => $this->resolveAsRecentSectionMode($rawOptions),
			$isSearchAllChatsEnabled => $this->resolveAsSearchAllChatsMode(),
			$isRecentSectionSearchEnabled, $needToFillDialogByRecent => $this->resolveAsRecentSectionMode($rawOptions),
			default => $this->resolveAsDefaultMode($rawOptions),
		};

		$this->applySearchFlags($rawOptions);
		$this->applyFilters($rawOptions);

		if ($this->chatTypeCondition !== null)
		{
			$this->flagOption->merge(FlagOption::byTypeCondition($this->chatTypeCondition));
		}
	}

	/**
	 * No type condition is built here: in folder mode the result set is a set of branches, and a
	 * single condition has nothing to be built out of. It also has to stay empty on purpose -
	 * {@see FlagOption::byTypeCondition()} is merged by AND and can only switch flags off, so an
	 * invented condition would take away the branches of people and of open chats.
	 *
	 * The mode is switched on by the presence of a non-null option key, so a missing key and a null
	 * value both leave the plain search in place ({@see self::isFolderScopeSearch()}). Inside the mode
	 * a value that does not read as a folder id stays 0 and the scope point reports such a folder
	 * unavailable: an unreachable folder ends in an empty result, never in a widened search.
	 */
	private function resolveAsFolderScopeMode(array $rawOptions): void
	{
		$this->isFolderScopeMode = true;

		$rawFolderId = $rawOptions[self::SEARCH_FOLDER_ID_OPTION];
		$this->folderScopeId = is_numeric($rawFolderId) ? (int)$rawFolderId : 0;
	}

	private function resolveAsSearchAllChatsMode(): void
	{
		$this->shouldToFillDefaultItems = false;
		$this->chatTypeCondition = $this->searchAllChatsTypeConditionProvider->getCondition();
	}

	private function resolveAsRecentSectionMode(array $rawOptions): void
	{
		$this->isRecentSectionMode = true;

		// Folder composition is a branch of the section mode: it keeps the root scope and the default
		// filling of the 'default' section, only the type condition comes from the folder rule.
		if (self::isFolderEligibleSearch($rawOptions))
		{
			$this->isFolderEligibleMode = true;
			$this->chatTypeCondition = EligibleChatTypes::getTypeCondition();

			return;
		}

		$section = $rawOptions[self::SEARCH_RECENT_SECTION_OPTION] ?? RecentConfigManager::DEFAULT_SECTION_NAME;
		if ($section !== RecentConfigManager::DEFAULT_SECTION_NAME)
		{
			$this->shouldToFillDefaultItems = false;
		}

		$this->chatTypeCondition = $this->typeRegistry->getConditionByRecentSection($section);
	}

	private function applyFilters(array $rawOptions): void
	{
		$this->withChatByUsers = ($rawOptions[self::WITH_CHAT_BY_USERS_OPTION] ?? false) === true;
		$this->onlyWithManageMessagesRight = ($rawOptions[self::ONLY_WITH_MANAGE_MESSAGES_RIGHT_OPTION] ?? false) === true;
		$this->onlyWithManageUsersAddRight = ($rawOptions[self::ONLY_WITH_MANAGE_USERS_ADD_RIGHT_OPTION] ?? false) === true;
		$this->onlyWithOwnerRight = ($rawOptions[self::ONLY_WITH_OWNER_RIGHT_OPTION] ?? false) === true;
		$this->onlyWithNullEntityType = ($rawOptions[self::ONLY_WITH_NULL_ENTITY_TYPE_OPTION] ?? false) === true;
		$this->fillDialogByRecent = ($rawOptions[self::FILL_DIALOG_BY_RECENT_OPTION] ?? false) === true;
		$this->excludeGuests = ($rawOptions[self::EXCLUDE_GUESTS_OPTION] ?? false) === true;
		$this->excludeCurrentUser = ($rawOptions[self::EXCLUDE_CURRENT_USER_OPTION] ?? false) === true;
		$this->mentionSearch = ($rawOptions[self::MENTION_SEARCH_OPTION] ?? false) === true;
		$this->contextChatId = isset($rawOptions[self::CONTEXT_CHAT_ID]) ? (int)$rawOptions[self::CONTEXT_CHAT_ID] : null;

		if (isset($rawOptions[self::EXCLUDE_IDS_OPTION]) && is_array($rawOptions[self::EXCLUDE_IDS_OPTION]))
		{
			$this->excludeIds = $rawOptions[self::EXCLUDE_IDS_OPTION];
		}

		$this->applyParentId($rawOptions);
		$this->applyChatTypeExclusion($rawOptions);
	}

	private function applyParentId(array $rawOptions): void
	{
		// in folder mode the nesting frame is a property of a branch, so the query gets none as a
		// whole - a frame the client asks for explicitly is not applied either
		if ($this->isFolderScopeMode)
		{
			$this->parentId = null;

			return;
		}

		if (array_key_exists(self::PARENT_ID_OPTION, $rawOptions))
		{
			$this->parentId = $this->normalizeParentId($rawOptions[self::PARENT_ID_OPTION]);

			return;
		}

		// recent-section search is scoped to root chats unless a parent is passed explicitly
		$this->parentId = $this->isRecentSectionMode ? 0 : null;
	}

	private function applyChatTypeExclusion(array $rawOptions): void
	{
		// an exclusion would create the very type condition folder mode leaves empty on purpose,
		// and switch the branches of the result set off through it
		if ($this->isFolderScopeMode)
		{
			return;
		}

		$excludeLiterals = $rawOptions[self::EXCLUDE_CHAT_TYPES_OPTION] ?? null;
		if (!is_array($excludeLiterals) || empty($excludeLiterals))
		{
			return;
		}

		$extraExclude = [];
		foreach ($excludeLiterals as $literal)
		{
			$extraExclude[] = $this->typeRegistry->getByLiteralAndEntity($literal, null);
		}

		$currentCondition = $this->chatTypeCondition ?? new TypeCondition();
		$this->chatTypeCondition = $currentCondition->merge(
			new TypeCondition(exclude: $extraExclude),
		);
	}

	private function resolveAsDefaultMode(array $rawOptions): void
	{
		if (isset($rawOptions[self::SEARCH_CHAT_TYPES_OPTION]) && is_array($rawOptions[self::SEARCH_CHAT_TYPES_OPTION]))
		{
			$chatLiterals = $rawOptions[self::SEARCH_CHAT_TYPES_OPTION];
			$types = [];
			foreach ($chatLiterals as $literal)
			{
				$types[] = $this->typeRegistry->getByLiteralAndEntity($literal, null);
			}
			$this->chatTypeCondition = new TypeCondition(include: $types);
		}
	}

	private function normalizeParentId(mixed $parentId): ?int
	{
		if ($parentId === null)
		{
			return null;
		}

		if (!is_numeric($parentId))
		{
			return 0;
		}

		$parentId = (int)$parentId;

		return $parentId > 0 ? $parentId : 0;
	}

	private function isNestedRecentSectionSearch(): bool
	{
		return $this->isRecentSectionMode && $this->parentId !== null && $this->parentId > 0;
	}

	private function applySearchFlags(array $rawOptions): void
	{
		$isIncludeOnly = isset($rawOptions[self::INCLUDE_ONLY_OPTION]) && is_array($rawOptions[self::INCLUDE_ONLY_OPTION]);
		$isExclude = isset($rawOptions[self::EXCLUDE_OPTION]) && is_array($rawOptions[self::EXCLUDE_OPTION]);

		if ($isIncludeOnly)
		{
			$this->flagOption->mergeWithIncludeOnlyList(SearchingFlag::fromStringArray($rawOptions[self::INCLUDE_ONLY_OPTION]));
		}
		elseif ($isExclude)
		{
			$this->flagOption->mergeWithExcludeList(SearchingFlag::fromStringArray($rawOptions[self::EXCLUDE_OPTION]));
		}
	}

	private function setDefaultState(): void
	{
		$this->flagOption = FlagOption::byDefault();
	}
}
