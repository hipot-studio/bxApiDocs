<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Im\Bot\Keyboard;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Link\Pin\PinService;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\BbCodeSanitizer;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ImGate;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\MessageEditor;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;

class AppChatMessageService
{
	private const OPEN_APP_ACTION = 'vibecodeconnector.Catalog.openAppPage';
	private const WELCOME_CLAIM_TTL = 300;

	public function __construct(
		private readonly BbCodeSanitizer $bbCodeSanitizer = new BbCodeSanitizer(),
		private readonly MessageEditor $messageEditor = new MessageEditor(),
		private readonly CatalogItemRepository $repository = new CatalogItemRepository(),
		private readonly ImGate $imGate = new ImGate(),
	) {}

	public function postWelcome(int $chatId, CatalogItem $item): ?int
	{
		if (!$this->imGate->isAvailable() || $chatId <= 0)
		{
			return null;
		}
		Chat::cleanCache($chatId);
		$chat = Chat::getInstance($chatId);
		if (($chat->getChatId() ?? 0) <= 0)
		{
			return null;
		}

		$claim = $this->claimWelcome((int)$item->getId(), $chatId);
		if ($claim === null)
		{
			return null;
		}

		$authorId = $item->getOwnerId();
		$message = (new Message())
			->setMessage($this->welcomeText($item))
			->setAuthorId($authorId);
		$this->setKeyboard($message, $this->buildOpenButton($item));
		$message->setContextUser($authorId);
		$sendResult = $chat->withContextUser($authorId)->sendMessage($message);
		$messageId = $sendResult->getMessageId();
		if (!$sendResult->isSuccess() || $messageId === null)
		{
			$this->repository->compareAndSetAppChatWelcomeMark((int)$item->getId(), $chatId, $claim, 0);

			return null;
		}

		$this->repository->compareAndSetAppChatWelcomeMark((int)$item->getId(), $chatId, $claim, $messageId);
		$pinResult = (new PinService())->setContext($message->getContext())->pinMessage($message);
		if (!$pinResult->isSuccess())
		{
			\AddMessage2Log(
				'Welcome message ' . $messageId . ' of chat ' . $chatId . ' was not pinned, '
					. implode('; ', $pinResult->getErrorMessages()),
				'vibecodeconnector',
			);
		}

		return $messageId;
	}

	public function syncWelcome(int $chatId, CatalogItem $item): void
	{
		if (!$this->imGate->isAvailable() || $chatId <= 0)
		{
			return;
		}
		$messageId = $this->findWelcomeMessageId($item, $chatId);
		if ($messageId !== null)
		{
			$this->messageEditor->setText($messageId, $item->getOwnerId(), $this->welcomeText($item));
		}
	}

	private function welcomeText(CatalogItem $item): string
	{
		$description = trim($this->bbCodeSanitizer->sanitize($item->getDescription() ?? ''));

		return $description === ''
			? (string)Loc::getMessage('VIBECODECONNECTOR_APP_CHAT_WELCOME_MESSAGE')
			: $description;
	}

	public function findWelcomeMessageId(CatalogItem $item, int $chatId): ?int
	{
		$mark = (int)$this->repository->getAppChatWelcomeMark((int)$item->getId(), $chatId);

		return $mark > 0 ? $mark : null;
	}

	private function claimWelcome(int $catalogItemId, int $chatId): ?int
	{
		$current = $this->repository->getAppChatWelcomeMark($catalogItemId, $chatId);
		if ($current === null || $current > 0)
		{
			return null;
		}
		if ($current < 0 && -$current > time() - self::WELCOME_CLAIM_TTL)
		{
			return null;
		}

		$claim = -time();

		return $this->repository->compareAndSetAppChatWelcomeMark($catalogItemId, $chatId, $current, $claim)
			? $claim
			: null;
	}

	private function setKeyboard(Message $message, array $button): void
	{
		$keyboard = new Keyboard();
		$keyboard->addButton($button);
		$message->setKeyboard($keyboard);
	}

	private function buildOpenButton(CatalogItem $item): array
	{
		return [
			'TEXT' => (string)Loc::getMessage('VIBECODECONNECTOR_APP_CHAT_OPEN_APP_BUTTON'),
			'LINK' => (string)UrlManager::getInstance()->create(
				self::OPEN_APP_ACTION,
				['catalogItemId' => (int)$item->getId()],
			),
			'DISPLAY' => 'BLOCK',
		];
	}
}
