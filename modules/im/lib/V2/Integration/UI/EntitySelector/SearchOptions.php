<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\UI\EntitySelector;

use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Integration\UI\EntitySelector\SearchOptions\FlagOption;
use Bitrix\Im\V2\Integration\UI\EntitySelector\SearchOptions\SearchingFlag;
use Bitrix\Im\V2\Recent\Config\RecentConfigManager;
use Bitrix\Main\DI\ServiceLocator;

class SearchOptions
{
	// Mode
	public const SEARCH_RECENT_SECTION_OPTION = 'searchRecentSection';
	public const INCLUDE_ONLY_OPTION = 'includeOnly';
	public const EXCLUDE_OPTION = 'exclude';
	public const SEARCH_CHAT_TYPES_OPTION = 'searchChatTypes';

	// Filters
	public const FILL_DIALOG_BY_RECENT_OPTION = 'fillDialogByRecent';

	public const WITH_CHAT_BY_USERS_OPTION = 'withChatByUsers';
	public const ONLY_WITH_MANAGE_MESSAGES_RIGHT_OPTION = 'onlyWithManageMessagesRight';
	public const ONLY_WITH_MANAGE_USERS_ADD_RIGHT_OPTION = 'onlyWithManageUsersAddRight';
	public const ONLY_WITH_OWNER_RIGHT_OPTION = 'onlyWithOwnerRight';
	public const ONLY_WITH_NULL_ENTITY_TYPE_OPTION = 'onlyWithNullEntityType';
	public const EXCLUDE_IDS_OPTION = 'excludeIds';
	public const CONTEXT_CHAT_ID = 'contextChatId';

	// Resolved state
	private FlagOption $flagOption;
	private ?TypeCondition $chatTypeCondition = null;
	private bool $isRecentSectionMode = false;
	private bool $shouldToFillDefaultItems = true;
	private ?int $contextChatId = null;

	// Filter options state
	private bool $withChatByUsers = false;
	private bool $onlyWithManageMessagesRight = false;
	private bool $onlyWithManageUsersAddRight = false;
	private bool $onlyWithOwnerRight = false;
	private bool $onlyWithNullEntityType = false;
	private bool $fillDialogByRecent = false;
	private array $excludeIds = [];

	private readonly TypeRegistry $typeRegistry;

	public function __construct(array $rawOptions)
	{
		$this->typeRegistry = ServiceLocator::getInstance()->get(TypeRegistry::class);
		$this->setDefaultState();
		$this->resolve($rawOptions);
	}

	public function isUserSearchEnabled(): bool
	{
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

	public function shouldFillDefaultItems(): bool
	{
		return $this->shouldToFillDefaultItems;
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

	private function resolve(array $rawOptions): void
	{
		$isRecentSectionSearchEnabled = isset($rawOptions[self::SEARCH_RECENT_SECTION_OPTION]) && is_string($rawOptions[self::SEARCH_RECENT_SECTION_OPTION]);
		$needToFillDialogByRecent = isset($rawOptions[self::FILL_DIALOG_BY_RECENT_OPTION]) && $rawOptions[self::FILL_DIALOG_BY_RECENT_OPTION] === true;

		match (true)
		{
			$isRecentSectionSearchEnabled, $needToFillDialogByRecent => $this->resolveAsRecentSectionMode($rawOptions),
			default => $this->resolveAsDefaultMode($rawOptions),
		};

		$this->applySearchFlags($rawOptions);
		$this->applyFilters($rawOptions);
	}

	private function resolveAsRecentSectionMode(array $rawOptions): void
	{
		$this->isRecentSectionMode = true;
		$section = $rawOptions[self::SEARCH_RECENT_SECTION_OPTION] ?? RecentConfigManager::DEFAULT_SECTION_NAME;
		if ($section !== RecentConfigManager::DEFAULT_SECTION_NAME)
		{
			$this->shouldToFillDefaultItems = false;
		}

		$this->chatTypeCondition = $this->typeRegistry->getConditionByRecentSection($section);
		$this->flagOption->merge(FlagOption::byTypeCondition($this->chatTypeCondition));
	}

	private function applyFilters(array $rawOptions): void
	{
		$this->withChatByUsers = ($rawOptions[self::WITH_CHAT_BY_USERS_OPTION] ?? false) === true;
		$this->onlyWithManageMessagesRight = ($rawOptions[self::ONLY_WITH_MANAGE_MESSAGES_RIGHT_OPTION] ?? false) === true;
		$this->onlyWithManageUsersAddRight = ($rawOptions[self::ONLY_WITH_MANAGE_USERS_ADD_RIGHT_OPTION] ?? false) === true;
		$this->onlyWithOwnerRight = ($rawOptions[self::ONLY_WITH_OWNER_RIGHT_OPTION] ?? false) === true;
		$this->onlyWithNullEntityType = ($rawOptions[self::ONLY_WITH_NULL_ENTITY_TYPE_OPTION] ?? false) === true;
		$this->fillDialogByRecent = ($rawOptions[self::FILL_DIALOG_BY_RECENT_OPTION] ?? false) === true;
		$this->contextChatId = isset($rawOptions[self::CONTEXT_CHAT_ID]) ? (int)$rawOptions[self::CONTEXT_CHAT_ID] : null;

		if (isset($rawOptions[self::EXCLUDE_IDS_OPTION]) && is_array($rawOptions[self::EXCLUDE_IDS_OPTION]))
		{
			$this->excludeIds = $rawOptions[self::EXCLUDE_IDS_OPTION];
		}
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
			$this->flagOption->merge(FlagOption::byTypeCondition($this->chatTypeCondition));
		}
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
