<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatDialogIdResolver;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Folder\Definition\ChatListDefinition;
use Bitrix\Im\V2\Folder\Definition\FolderDefinition;
use Bitrix\Im\V2\Folder\Eligibility\EligibleChatTypes;
use Bitrix\Main\DI\ServiceLocator;

class PersonalFolder extends BaseFolder
{
	public const TYPE = FolderType::Personal->value;

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

	public function getDefinition(): FolderDefinition
	{
		return new ChatListDefinition($this->getChatIds());
	}

	public function containsChat(Chat $chat): bool
	{
		return $this->getDefinition()->containsChat((int)$chat->getChatId());
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

		// Access alone is not enough: an open chat is accessible without membership, and a folder row
		// for a chat outside the recent list of its owner would show up nowhere. A hidden relation is
		// membership too, so the relation is asked for as a whole and not through its IS_HIDDEN.
		if ($chat->getRelationByUserId($userId) === null)
		{
			return false;
		}

		if (!EligibleChatTypes::isAllowed($chat))
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
			'visible' => $this->isVisible(),
			'description' => $this->getDescription(),
			'displaysNestedInRoot' => $this->displaysNestedInRoot(),
			'definition' => (new ChatListDefinition($this->getChatIds(), $this->resolveDialogIdMap($option)))->toRestFormat(),
		];
	}

	/**
	 * Dialog ids for the composition, preserving {@see getChatIds} order. Uses the injected
	 * batch map when present, otherwise resolves this folder's chat ids on its own.
	 *
	 * @param array $option see {@see toRestFormat}
	 * @return array<int, string> chatId => dialogId
	 */
	private function resolveDialogIdMap(array $option): array
	{
		$chatIds = $this->getChatIds();
		if ($chatIds === [])
		{
			return [];
		}

		if (isset($option['dialogIdMap']) && is_array($option['dialogIdMap']))
		{
			return $option['dialogIdMap'];
		}

		return ServiceLocator::getInstance()
			->get(ChatDialogIdResolver::class)
			->resolve($chatIds, (int)$this->getUserId())
		;
	}
}
