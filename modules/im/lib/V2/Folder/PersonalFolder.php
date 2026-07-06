<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Recent\Config\RecentConfigManager;

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

	public function toRestFormat(array $option = []): array
	{
		$chatIds = isset($option['chatIds']) && is_array($option['chatIds'])
			? Normalizer::toUniquePositiveIntegers($option['chatIds'])
			: $this->getChatIds();

		return [
			'id' => $this->getId(),
			'type' => self::TYPE,
			'title' => $this->getTitle(),
			'displaysNestedInRoot' => $this->displaysNestedInRoot(),
			'definition' => [
				'chatIds' => $chatIds,
			],
		];
	}
}
