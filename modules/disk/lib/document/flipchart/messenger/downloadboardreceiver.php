<?php

namespace Bitrix\Disk\Document\Flipchart\Messenger;

use Bitrix\Disk\Document\Flipchart\BoardService;
use Bitrix\Disk\Document\Flipchart\SessionManager;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

class DownloadBoardReceiver extends AbstractReceiver
{

	protected function process(MessageInterface $message): void
	{
		/** @var DownloadBoardMessage $message */
		$manager = new SessionManager();
		$manager->setExternalHash($message->sessionId);
		$manager->setUserId((int)$message->userId);

		$session = $manager->findSession();
		if (!$session)
		{
			return;
		}
		$boardService = new BoardService($session);
		$boardService->saveDocument(attempt: $message->attempt);
	}
}
