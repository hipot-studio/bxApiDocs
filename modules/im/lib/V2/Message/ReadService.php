<?php

namespace Bitrix\Im\V2\Message;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\CountersUpdater;
use Bitrix\Im\V2\Reading\Unreader;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Im\V2\Anchor;

class ReadService
{
	use ContextCustomer
	{
		setContext as private defaultSetContext;
	}

	protected CounterService $counterService;
	protected Anchor\ReadService $anchorReadService;

	private static array $lastMessageIdCache = [];

	public function __construct(?int $userId = null)
	{
		$this->counterService = new CounterService();
		$this->anchorReadService = new Anchor\ReadService();

		if (isset($userId))
		{
			$context = new Context();
			$context->setUser($userId);
			$this->setContext($context);
			$this->counterService->setContext($context);
			$this->anchorReadService->setContext($context);
		}
	}

	/**
	 * @deprecated
	 * @use \Bitrix\Im\V2\Reading\Counter\CountersUpdater::delete
	 * or
	 * @use \Bitrix\Im\V2\Reading\Reader::readAllInChat
	 */
	public function readAllInChat(int $chatId): Result
	{
		$userId = $this->getContext()->getUserId();
		$countersUpdater = ServiceLocator::getInstance()->get(CountersUpdater::class);
		$countersUpdater->delete()->byChat($chatId)->forUser($this->context->getUserId())->execute();

		$this->updateDateRecent($chatId);
		$this->anchorReadService->readByChatId($chatId);

		$chat = Chat::getInstance($chatId);
		$chat->onAfterAllMessagesRead($userId);
		if ($chat instanceof Chat\ChannelChat)
		{
			$parentChatId = $chat->getId();
			Application::getInstance()->addBackgroundJob(
				fn () => $countersUpdater->delete()->byParent($parentChatId)->forUser($userId)->execute()
			);
		}

		$counter = ServiceLocator::getInstance()->get(CountersProvider::class)?->getForUser($chatId, $userId);

		return (new Result())->setResult(['COUNTER' => $counter, 'VIEWED_MESSAGES' => new MessageCollection()]);
	}

	/**
	 * @deprecated
	 * @use \Bitrix\Im\V2\Reading\Unreader::unreadTo
	 */
	public function unreadTo(Message $message): Result
	{
		return ServiceLocator::getInstance()->get(Unreader::class)->unreadTo($message, $this->context->getUserId());
	}

	/**
	 * @deprecated
	 * @use \Bitrix\Im\V2\Chat::getLastMessageId
	 */
	public function getLastMessageIdInChat(int $chatId): int
	{
		return Chat::getInstance($chatId)->getLastMessageId() ?? 0;
	}

	public function getCounterService(): CounterService
	{
		return $this->counterService;
	}

	public function getAnchorReadService(): Anchor\ReadService
	{
		return $this->anchorReadService;
	}

	public function setContext(?Context $context): self
	{
		$this->defaultSetContext($context);
		$this->getCounterService()->setContext($context);
		$this->getAnchorReadService()->setContext($context);

		return $this;
	}

	private function updateDateRecent(int $chatId): void
	{
		$userId = $this->getContext()->getUserId();
		\Bitrix\Main\Application::getConnection()->query(
			"UPDATE b_im_recent SET DATE_UPDATE = NOW() WHERE USER_ID = {$userId} AND ITEM_CID = {$chatId}"
		);
	}
}
