<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\OnlyOffice\Handlers;

use Bitrix\Disk\Document\OnlyOffice\RestrictionManager;
use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Disk\Realtime\Events\AvailableDocumentSessionCountEvent;
use Bitrix\Main\Application;
use Bitrix\Main\Event;

class AvailableDocumentSessionCountNotifier
{
	public const COMMAND_NAME = 'updateAvailableDocumentSessionCount';

	public static function handleSessionCountChanges(Event $event): void
	{
		self::processEvent(
			(int)$event->getParameter('availableDocumentSessionCount')
		);
	}

	public static function handleBalanceChanges(Event $event): void
	{
		if ($event->getParameter('service') === BaasSessionBoostService::SERVICE_CODE)
		{
			$availableDocumentSessionCount = (new RestrictionManager())->getAvailableDocumentSessionCount();
			self::processEvent($availableDocumentSessionCount);
		}
	}

	private static function processEvent(int $availableDocumentSessionCount): void
	{
		Application::getInstance()->addBackgroundJob(
			fn () => self::sendPush($availableDocumentSessionCount)
		);
	}

	public static function sendPush(int $availableDocumentSessionCount): void
	{
		$realTimeEvent = new AvailableDocumentSessionCountEvent('updateAvailableDocumentSessionCount', [
			'value' => $availableDocumentSessionCount,
		]);
		$realTimeEvent->sendToUsers();
	}
}