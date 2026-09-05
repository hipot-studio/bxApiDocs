<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatDialogIdResolver;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Recent\Config\RecentConfigManager;
use Bitrix\Main\DI\ServiceLocator;

class PersonalFolder extends BaseFolder
{
	public const TYPE = FolderType::Personal->value;

	private const ELIGIBLE_RECENT_SECTIONS = [
		RecentConfigManager::DEFAULT_SECTION_NAME,
	];

	/** @var int[]|null preloaded chat ids — populated by {@see FolderProvider::getByUser} batch-load. */
	protected ?array $chatIds = null;

	public function getType(): string
	{
		return self::TYPE;
	}

	public function getCode(): ?string
	{
		return null;
	}

	public function getTitle(): string
	{
		return parent::getTitle();
	}

	public function getRecentSection(): ?string
	{
		return null;
	}

	/**
	 * Returns preloaded chat ids (typical path) or empty array if hydrate
	 * has not been performed. Entity does not lazy-fetch from DB on read.
	 *
	 * @return int[]
	 */
	public function getChatIds(): array
	{
		return $this->chatIds ?? [];
	}

	/**
	 * Internal setter used by {@see FolderProvider} batch-load.
	 *
	 * @param int[] $chatIds
	 */
	public function setChatIds(array $chatIds): void
	{
		$this->chatIds = Normalizer::toUniquePositiveIntegers($chatIds);
	}

	public function containsChat(Chat $chat): bool
	{
		return in_array((int)$chat->getChatId(), $this->getChatIds(), true);
	}

	public function canAcceptChat(Chat $chat): bool
	{
		if (!$chat->isExist())
		{
			return false;
		}

		$userId = (int)($this->getUserId() ?? 0);
		if (!$chat->checkAccess($userId)->isSuccess())
		{
			return false;
		}

		if (empty(array_intersect($chat->getRecentSections(), self::ELIGIBLE_RECENT_SECTIONS)))
		{
			return false;
		}

		$scope = $this->getRecentParentScope();
		$chatParent = (int)($chat->getParentChatId() ?? 0);

		return $scope === null || $scope === $chatParent;
	}

	/**
	 * @param array $option supported keys:
	 *  - 'dialogIdMap' array<int, string> chatId => dialogId — batch-resolved map injected by
	 *    {@see FolderCollection::toRestFormat} to avoid N+1. When absent, this folder resolves
	 *    its own chat ids via {@see ChatDialogIdResolver} (single-folder path: add/get/update/pull).
	 */
	public function toRestFormat(array $option = []): array
	{
		return [
			'id' => $this->getId(),
			'type' => self::TYPE,
			'title' => $this->getTitle(),
			'sort' => $this->getSort(),
			'displaysNestedInRoot' => $this->displaysNestedInRoot(),
			'definition' => [
				'chats' => $this->buildChats($option),
			],
		];
	}

	/**
	 * Composition as identity pairs (chatId + dialogId), preserving {@see getChatIds} order.
	 * Carries identity only — never the full chat entity. Empty composition yields [].
	 *
	 * @param array $option see {@see toRestFormat}
	 * @return array<int, array{chatId: int, dialogId: string}>
	 */
	private function buildChats(array $option): array
	{
		$chatIds = $this->getChatIds();
		if ($chatIds === [])
		{
			return [];
		}

		$dialogIdMap = isset($option['dialogIdMap']) && is_array($option['dialogIdMap'])
			? $option['dialogIdMap']
			: ServiceLocator::getInstance()
				->get(ChatDialogIdResolver::class)
				->resolve($chatIds, (int)$this->getUserId())
		;

		$chats = [];
		foreach ($chatIds as $chatId)
		{
			$chatId = (int)$chatId;
			$chats[] = [
				'chatId' => $chatId,
				// Map is complete (resolver returns an entry for every positive chat id); the
				// fallback is a guard only and defers the "chatNNN" format to its single source.
				'dialogId' => (string)($dialogIdMap[$chatId] ?? ChatDialogIdResolver::getGroupDialogId($chatId)),
			];
		}

		return $chats;
	}
}
