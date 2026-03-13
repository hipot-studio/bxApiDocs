<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\UI\EntitySelector;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
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
	public const WITH_CHAT_BY_USERS_OPTION = 'withChatByUsers';
	public const ONLY_WITH_MANAGE_MESSAGES_RIGHT_OPTION = 'onlyWithManageMessagesRight';
	public const ONLY_WITH_MANAGE_USERS_ADD_RIGHT_OPTION = 'onlyWithManageUsersAddRight';
	public const ONLY_WITH_OWNER_RIGHT_OPTION = 'onlyWithOwnerRight';
	public const ONLY_WITH_NULL_ENTITY_TYPE_OPTION = 'onlyWithNullEntityType';
	public const EXCLUDE_IDS_OPTION = 'excludeIds';

	// Search Flags
	public const FLAG_USERS = 'users';
	public const FLAG_CHATS = 'chats';
	public const FLAG_BOTS = 'bots';
	private const ALLOWED_SEARCH_FLAGS = [self::FLAG_USERS, self::FLAG_CHATS, self::FLAG_BOTS];

	// Resolved state
	private bool $userSearchEnabled;
	private bool $chatSearchEnabled;
	private bool $botSearchEnabled;
	/** @var Type[] */
	private array $chatTypesToSearch = [];
	private bool $isRecentSectionMode = false;
	private bool $shouldToFillDefaultItems = true;

	// Filter options state
	private bool $withChatByUsers = false;
	private bool $onlyWithManageMessagesRight = false;
	private bool $onlyWithManageUsersAddRight = false;
	private bool $onlyWithOwnerRight = false;
	private bool $onlyWithNullEntityType = false;
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
		return $this->userSearchEnabled;
	}

	public function isChatSearchEnabled(): bool
	{
		return $this->chatSearchEnabled;
	}

	public function isBotSearchEnabled(): bool
	{
		return $this->botSearchEnabled;
	}

	public function getChatTypesToSearch(): array
	{
		return $this->chatTypesToSearch;
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

	private function resolve(array $rawOptions): void
	{
		match (true)
		{
			isset($rawOptions[self::SEARCH_RECENT_SECTION_OPTION]) => $this->resolveAsRecentSectionMode($rawOptions),
			isset($rawOptions[self::INCLUDE_ONLY_OPTION]), isset($rawOptions[self::EXCLUDE_OPTION]) => $this->resolveAsFlagsMode($rawOptions),
			default => $this->resolveAsDefaultMode($rawOptions),
		};

		$this->applyFilters($rawOptions);
	}

	private function resolveAsRecentSectionMode(array $rawOptions): void
	{
		$this->isRecentSectionMode = true;
		$section = $rawOptions[self::SEARCH_RECENT_SECTION_OPTION];
		if ($section !== RecentConfigManager::DEFAULT_SECTION_NAME)
		{
			$this->shouldToFillDefaultItems = false;
		}

		$this->chatTypesToSearch = $this->typeRegistry->getByRecentSection($section);
		$this->chatSearchEnabled = !empty($this->chatTypesToSearch);

		if (!$this->needToSearchUsersByTypes($this->chatTypesToSearch))
		{
			$this->userSearchEnabled = false;
		}
	}

	/**
	 * @param Type[] $types
	 */
	private function needToSearchUsersByTypes(array $types): bool
	{
		foreach ($types as $type)
		{
			if ($type->literal === Chat::IM_TYPE_PRIVATE)
			{
				return true;
			}
		}

		return false;
	}

	private function resolveAsFlagsMode(array $rawOptions): void
	{
		$this->applySearchFlags($rawOptions);
		$this->applyChatTypes($rawOptions);
	}

	private function resolveAsDefaultMode(array $rawOptions): void
	{
		$this->applyChatTypes($rawOptions);
	}

	private function applyFilters(array $rawOptions): void
	{
		$this->withChatByUsers = ($rawOptions[self::WITH_CHAT_BY_USERS_OPTION] ?? false) === true;
		$this->onlyWithManageMessagesRight = ($rawOptions[self::ONLY_WITH_MANAGE_MESSAGES_RIGHT_OPTION] ?? false) === true;
		$this->onlyWithManageUsersAddRight = ($rawOptions[self::ONLY_WITH_MANAGE_USERS_ADD_RIGHT_OPTION] ?? false) === true;
		$this->onlyWithOwnerRight = ($rawOptions[self::ONLY_WITH_OWNER_RIGHT_OPTION] ?? false) === true;
		$this->onlyWithNullEntityType = ($rawOptions[self::ONLY_WITH_NULL_ENTITY_TYPE_OPTION] ?? false) === true;

		if (isset($rawOptions[self::EXCLUDE_IDS_OPTION]) && is_array($rawOptions[self::EXCLUDE_IDS_OPTION]))
		{
			$this->excludeIds = $rawOptions[self::EXCLUDE_IDS_OPTION];
		}
	}

	private function applyChatTypes(array $rawOptions): void
	{
		if (isset($rawOptions[self::SEARCH_CHAT_TYPES_OPTION]) && is_array($rawOptions[self::SEARCH_CHAT_TYPES_OPTION]))
		{
			$chatLiterals = $rawOptions[self::SEARCH_CHAT_TYPES_OPTION];
			$this->chatTypesToSearch = [];
			foreach ($chatLiterals as $literal)
			{
				$this->chatTypesToSearch[] = $this->typeRegistry->getByLiteralAndEntity($literal, null);
			}
		}
	}

	private function applySearchFlags(array $rawOptions): void
	{
		$searchFlags = [
			self::FLAG_USERS => true,
			self::FLAG_CHATS => true,
			self::FLAG_BOTS => true,
		];

		if (isset($rawOptions[self::INCLUDE_ONLY_OPTION]) && is_array($rawOptions[self::INCLUDE_ONLY_OPTION]))
		{
			$searchFlags = [
				self::FLAG_USERS => false,
				self::FLAG_CHATS => false,
				self::FLAG_BOTS => false,
			];
			foreach ($rawOptions[self::INCLUDE_ONLY_OPTION] as $flag)
			{
				if (in_array($flag, self::ALLOWED_SEARCH_FLAGS, true))
				{
					$searchFlags[$flag] = true;
				}
			}
		}
		elseif (isset($rawOptions[self::EXCLUDE_OPTION]) && is_array($rawOptions[self::EXCLUDE_OPTION]))
		{
			foreach ($rawOptions[self::EXCLUDE_OPTION] as $flag)
			{
				if (in_array($flag, self::ALLOWED_SEARCH_FLAGS, true))
				{
					$searchFlags[$flag] = false;
				}
			}
		}

		$this->userSearchEnabled = $searchFlags[self::FLAG_USERS];
		$this->chatSearchEnabled = $searchFlags[self::FLAG_CHATS];
		$this->botSearchEnabled = $searchFlags[self::FLAG_BOTS];
	}

	private function setDefaultState(): void
	{
		$this->userSearchEnabled = true;
		$this->chatSearchEnabled = true;
		$this->botSearchEnabled = true;
	}
}
