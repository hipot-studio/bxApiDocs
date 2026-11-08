<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save\Messenger;

use Bitrix\Disk\Internal\Service\OnlyOffice\Save\KeptDocumentRecovery;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

class KeptDocumentSaveReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		/** @var KeptDocumentSaveMessage $message */
		ServiceLocator::getInstance()
			->get(KeptDocumentRecovery::class)
			->continueRecovery($message->sessionId, $message->attempt)
		;
	}
}
