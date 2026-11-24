<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant;

use Bitrix\Disk\File;
use Bitrix\Disk\Realtime\Events\ObjectEvent;
use Bitrix\Main\Application;
use Bitrix\Main\Event;

final class HtmlAiLockPullHandler
{
	public static function onLockSet(Event $event): void
	{
		self::send($event, 'htmlAiLockSet');
	}

	public static function onLockRemoved(Event $event): void
	{
		self::send($event, 'htmlAiLockRemoved');
	}

	private static function send(Event $event, string $command): void
	{
		try
		{
			$file = File::loadById((int)$event->getParameter('fileId'));
			if (!$file)
			{
				return;
			}

			(new ObjectEvent($file, $command, [
				'fileId' => (int)$file->getRealObjectId(),
				'operationId' => $event->getParameter('operationId'),
				'expiresAt' => $event->getParameter('expiresAt'),
			]))->sendToObjectChannel();
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}
}
