<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Chat\Cleanup\ChatContentCollector;
use Bitrix\Im\V2\Chat\Update\UpdateFields;
use Bitrix\Im\V2\Chat\Update\UpdateService;
use Bitrix\Im\V2\Entity\File\ChatAvatar;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Messenger\Entity\ProcessingParam\ItemIdParam;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppChatOrigin;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppChatType;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ChatLocator;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CompareAndSetAppChatResult;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\AppChatDeletionMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\QueueId;

class AppChatService
{
	private const CREATE_LOCK_PREFIX = 'vibecodeconnector.app_chat.create.';
	private const CREATION_LOCK_MISS_AUDIT_TYPE = 'VIBECODECONNECTOR_APP_CHAT_CREATION_LOCK_MISS';

	public function __construct(
		private readonly CatalogItemRepository $repository = new CatalogItemRepository(),
		private readonly AppChatMessageService $messageService = new AppChatMessageService(),
		private readonly ChatLocator $chatLocator = new ChatLocator(),
		private readonly ?\Closure $messengerAvailable = null,
		private readonly AppChatAvailability $availability = new AppChatAvailability(),
	) {}

	public function ensureAppChat(CatalogItem $item): int
	{
		return $this->ensureAppChatWithResult($item)->chatId;
	}

	public function ensureAppChatWithResult(CatalogItem $item): AppChatEnsureResult
	{
		return $this->ensureAppChatWithBindingMode($item, false);
	}

	public function ensureAppChatOnDemandWithResult(CatalogItem $item): AppChatEnsureResult
	{
		return $this->ensureAppChatWithBindingMode($item, true);
	}

	private function ensureAppChatWithBindingMode(
		CatalogItem $item,
		bool $bumpSyncRevisionOnNewBinding,
	): AppChatEnsureResult
	{
		if ($item->getType() !== CatalogItemType::Application)
		{
			return new AppChatEnsureResult(0, false);
		}
		if (!$this->availability->isEnabled())
		{
			return new AppChatEnsureResult(0, false);
		}

		if (!$this->isMessengerAvailable())
		{
			return new AppChatEnsureResult(0, false);
		}

		$itemId = (int)$item->getId();
		if ($itemId <= 0)
		{
			return new AppChatEnsureResult(0, false);
		}

		$existing = $this->findValidLinkedChat($item, $itemId);
		if ($existing !== null)
		{
			return new AppChatEnsureResult($this->finalizeExistingChat($existing, $item), false);
		}

		if (!$this->acquireCreationLock($itemId))
		{
			$currentChatId = $this->findCurrentValidChatId($itemId);
			if ($currentChatId <= 0)
			{
				$this->logCreationLockMiss($itemId);
			}

			return new AppChatEnsureResult($currentChatId, false);
		}

		try
		{
			$current = $this->repository->getById($itemId);
			if (
				$current === null
				|| $current->isDeactivated()
				|| $current->getType() !== CatalogItemType::Application
			)
			{
				return new AppChatEnsureResult(0, false);
			}

			$result = $this->ensureAppChatLocked($current, $bumpSyncRevisionOnNewBinding);
			if ($result->chatId > 0)
			{
				$item->setAppChatId($result->chatId);
			}

			return $result;
		}
		finally
		{
			$this->releaseCreationLock($itemId);
		}
	}

	public function getValidLinkedChatId(CatalogItem $item): int
	{
		if (
			$item->getType() !== CatalogItemType::Application
			|| !$this->availability->isEnabled()
			|| !$this->isMessengerAvailable()
		)
		{
			return 0;
		}

		$itemId = (int)$item->getId();
		if ($itemId <= 0)
		{
			return 0;
		}

		$chat = $this->findValidLinkedChat($item, $itemId);

		return $chat === null ? 0 : (int)$chat->getChatId();
	}

	private function ensureAppChatLocked(
		CatalogItem $item,
		bool $bumpSyncRevisionOnNewBinding,
	): AppChatEnsureResult
	{
		$itemId = (int)$item->getId();
		$linkedChatId = (int)$item->getAppChatId();
		if ($linkedChatId > 0)
		{
			$linkedChat = $this->chatLocator->find($linkedChatId);
			if ($this->isAppChatForItem($linkedChat, $itemId))
			{
				return new AppChatEnsureResult($this->finalizeExistingChat($linkedChat, $item), false);
			}

			$this->writeAppChatBinding($item, $linkedChatId, null);
		}

		$avatarId = (int)$item->getIconFileId();
		$addResult = AppChatOrigin::create(static fn () => ChatFactory::getInstance()->addChat([
			'TYPE' => Chat::IM_TYPE_EXTERNAL,
			'ENTITY_TYPE' => AppChatType::ENTITY_TYPE,
			'ENTITY_ID' => $itemId,
			'AUTHOR_ID' => $item->getOwnerId(),
			'TITLE' => $item->getTitle(),
			'DESCRIPTION' => $item->getDescription() ?? '',
			'AVATAR' => $avatarId > 0 ? $avatarId : null,
			'USERS' => [$item->getOwnerId()],
			'SKIP_ADD_MESSAGE' => 'Y',
		]));
		if (!$addResult->isSuccess())
		{
			throw new \RuntimeException(
				'App chat creation failed: ' . implode('; ', $addResult->getErrorMessages()),
			);
		}

		$chatId = (int)$addResult->getChatId();
		$chat = $addResult->getChat();
		if ($chatId <= 0 || !$this->isAppChatForItem($chat, $itemId))
		{
			if ($chatId > 0)
			{
				$this->deleteUnboundChat($chatId, $itemId, $item->getOwnerId());
			}

			throw new \RuntimeException('The created app chat does not match the catalog item');
		}

		$bindingChanged = false;
		if ((int)$item->getAppChatId() !== $chatId)
		{
			$bindingResult = $bumpSyncRevisionOnNewBinding
				? $this->writeOnDemandAppChatBinding($item, (int)$item->getAppChatId(), $chatId)
				: $this->writeAppChatBinding($item, (int)$item->getAppChatId(), $chatId);
			if (!$bindingResult->succeeded)
			{
				$message = new AppChatDeletionMessage(
					chatId: $chatId,
					ownerId: $item->getOwnerId(),
					catalogItemId: $itemId,
					onlyIfUnbound: true,
				);
				try
				{
					$this->sendDeletionIntent($message);
				}
				catch (\Throwable $queueException)
				{
					if (!$this->deleteUnboundChat($chatId, $itemId, $item->getOwnerId()))
					{
						throw $queueException;
					}
				}

				$currentItem = $this->repository->getById($itemId);
				$currentChatId = (int)$currentItem?->getAppChatId();
				$currentChat = $this->chatLocator->find($currentChatId);
				if ($currentItem === null || !$this->isAppChatForItem($currentChat, $itemId))
				{
					return new AppChatEnsureResult(0, false);
				}

				$this->applyIcon($currentChat, (int)$currentItem->getIconFileId());
				$this->messageService->postWelcome($currentChatId, $currentItem);

				return new AppChatEnsureResult($currentChatId, false);
			}

			$bindingChanged = $bindingResult->changed;
		}
		$this->applyIcon($chat, $avatarId);
		$this->messageService->postWelcome($chatId, $item);

		return new AppChatEnsureResult($chatId, $bindingChanged);
	}

	private function findValidLinkedChat(CatalogItem $item, int $itemId): ?Chat
	{
		$chatId = (int)$item->getAppChatId();
		if ($chatId <= 0)
		{
			return null;
		}

		$chat = $this->chatLocator->find($chatId);

		return $this->isAppChatForItem($chat, $itemId) ? $chat : null;
	}

	private function finalizeExistingChat(Chat $chat, CatalogItem $item): int
	{
		$chatId = (int)$chat->getChatId();
		$this->applyIcon($chat, (int)$item->getIconFileId());
		$this->messageService->postWelcome($chatId, $item);

		return $chatId;
	}

	private function findCurrentValidChatId(int $itemId): int
	{
		$current = $this->repository->getById($itemId);
		if (
			$current === null
			|| $current->isDeactivated()
			|| $current->getType() !== CatalogItemType::Application
		)
		{
			return 0;
		}

		$chat = $this->findValidLinkedChat($current, $itemId);

		return $chat === null ? 0 : $this->finalizeExistingChat($chat, $current);
	}

	protected function acquireCreationLock(int $catalogItemId): bool
	{
		return Application::getConnection()->lock(self::CREATE_LOCK_PREFIX . $catalogItemId, 0);
	}

	protected function releaseCreationLock(int $catalogItemId): void
	{
		Application::getConnection()->unlock(self::CREATE_LOCK_PREFIX . $catalogItemId);
	}

	private function logCreationLockMiss(int $catalogItemId): void
	{
		try
		{
			\CEventLog::Add([
				'SEVERITY' => \CEventLog::SEVERITY_INFO,
				'AUDIT_TYPE_ID' => self::CREATION_LOCK_MISS_AUDIT_TYPE,
				'MODULE_ID' => 'vibecodeconnector',
				'ITEM_ID' => $catalogItemId,
				'DESCRIPTION' => 'App chat creation skipped because the item lock is held',
			]);
		}
		catch (\Throwable)
		{
		}
	}

	public function syncMetadataNow(int $itemId): ?Chat
	{
		if ($itemId <= 0 || !$this->availability->isEnabled() || !$this->isMessengerAvailable())
		{
			return null;
		}

		$item = $this->repository->getById($itemId);
		if ($item === null)
		{
			return null;
		}

		$chatId = (int)$item->getAppChatId();
		$chat = $this->chatLocator->find($chatId);
		if (!$this->isAppChatForItem($chat, $itemId))
		{
			return null;
		}

		$chat = $chat->withContextUser($item->getOwnerId());
		$this->applyIcon($chat, (int)$item->getIconFileId());
		$title = $chat->getTitle() === $item->getTitle() ? null : $item->getTitle();
		$description = ($chat->getDescription() ?? '') === ($item->getDescription() ?? '')
			? null
			: (string)$item->getDescription();
		if ($title !== null || $description !== null)
		{
			$fields = new UpdateFields(title: $title, description: $description);
			$updateResult = (new UpdateService($chat, $fields))->updateChat();
			$updatedChat = $updateResult->getResult();
			if (!$updateResult->isSuccess() || !$updatedChat instanceof Chat)
			{
				$this->messageService->syncWelcome($chatId, $item);

				return null;
			}

			$chat = $updatedChat;
		}

		$this->messageService->syncWelcome($chatId, $item);

		return $chat;
	}

	public function deleteAppChat(CatalogItem $item): void
	{
		$chatId = (int)$item->getAppChatId();
		$catalogItemId = (int)$item->getId();
		if ($chatId <= 0 || $catalogItemId <= 0)
		{
			return;
		}

		$ownerId = $item->getOwnerId();
		if ($this->isMessengerAvailable())
		{
			$chat = $this->chatLocator->find($chatId);
			if (
				$chat !== null
				&& !$this->isAppChatForItem($chat, $catalogItemId)
			)
			{
				$this->writeAppChatBinding($item, $chatId, null);

				return;
			}

			if ($ownerId <= 0 && $chat !== null)
			{
				$ownerId = (int)$chat->getAuthorId();
			}
		}
		if ($ownerId <= 0)
		{
			return;
		}

		$this->scheduleDeletion($item, $ownerId);

		$this->writeAppChatBinding($item, $chatId, null);
	}

	public function scheduleDeletion(CatalogItem $item, ?int $ownerId = null): void
	{
		$chatId = (int)$item->getAppChatId();
		$catalogItemId = (int)$item->getId();
		$ownerId ??= $item->getOwnerId();
		if ($chatId <= 0 || $catalogItemId <= 0 || $ownerId <= 0)
		{
			return;
		}

		$this->sendDeletionIntent(new AppChatDeletionMessage(
			chatId: $chatId,
			ownerId: $ownerId,
			catalogItemId: $catalogItemId,
		));
	}

	private function writeAppChatBinding(
		CatalogItem $item,
		int $expectedChatId,
		?int $chatId,
	): CompareAndSetAppChatResult
	{
		$result = $this->repository->compareAndSetAppChatWithResult(
			(int)$item->getId(),
			$expectedChatId,
			$chatId,
		);
		if ($result->succeeded)
		{
			$item->setAppChatId($chatId);
		}

		return $result;
	}

	private function writeOnDemandAppChatBinding(
		CatalogItem $item,
		int $expectedChatId,
		int $chatId,
	): CompareAndSetAppChatResult
	{
		$result = $this->repository->compareAndSetAppChatAndBumpSyncRevisionWithResult(
			(int)$item->getId(),
			$expectedChatId,
			$chatId,
		);
		if ($result->succeeded)
		{
			$item->setAppChatId($chatId);
		}

		return $result;
	}

	private function deleteUnboundChat(int $chatId, int $catalogItemId, int $ownerId): bool
	{
		$currentItem = $this->repository->getById($catalogItemId);
		if ($currentItem !== null && (int)$currentItem->getAppChatId() === $chatId)
		{
			return false;
		}

		$chat = $this->chatLocator->find($chatId);
		if ($chat === null)
		{
			return true;
		}
		if (!$this->isAppChatForItem($chat, $catalogItemId) || $ownerId <= 0)
		{
			return false;
		}

		(new ChatContentCollector($chatId))->deleteChat($ownerId);

		return true;
	}

	protected function sendDeletionIntent(AppChatDeletionMessage $message): void
	{
		$message->send(
			QueueId::AppChatDeletion->value,
			[new ItemIdParam($message->chatId)],
		);
	}

	protected function isMessengerAvailable(): bool
	{
		return $this->messengerAvailable !== null
			? ($this->messengerAvailable)()
			: Loader::includeModule('im');
	}

	private function isAppChatForItem(?Chat $chat, int $catalogItemId): bool
	{
		return $chat !== null
			&& $chat->getType() === Chat::IM_TYPE_EXTERNAL
			&& $chat->getEntityType() === AppChatType::ENTITY_TYPE
			&& (string)$chat->getEntityId() === (string)$catalogItemId;
	}

	private function applyIcon(Chat $chat, int $iconFileId): void
	{
		if ((int)$chat->getAvatarId() === $iconFileId)
		{
			return;
		}

		(new ChatAvatar($chat))->updateSystemAvatar($iconFileId, withMessage: false, skipRecent: true);
	}
}
