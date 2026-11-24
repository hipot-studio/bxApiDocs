<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant;

use Bitrix\Disk\Internal\Service\HtmlEditor\AiLockService;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;

final class HtmlAiTerminalHandler
{
	public static function handle(Event $event): void
	{
		$chatId = $event->getParameter('chatId');
		$messageId = $event->getParameter('messageId');
		if (
			!is_int($chatId) || $chatId <= 0
			|| !is_int($messageId) || $messageId <= 0
			|| !in_array($event->getParameter('outcome'), ['completed', 'error', 'stopped'], true)
			|| !Loader::includeModule('im')
		)
		{
			return;
		}

		try
		{
			$message = new Message($messageId);
			if ($message->getChatId() !== $chatId)
			{
				return;
			}

			$operationId = $message->getUuid();
			$context = $message->getParams()->get(Params::COPILOT_PAGE_CONTEXT)->getValue();
			$fileId = $context['custom']['disk']['file']['objectId'] ?? null;
			if (
				!is_string($operationId) || !AiLockService::isValidOperationId($operationId)
				|| (!is_int($fileId) && !is_string($fileId))
				|| preg_match('/^[1-9][0-9]*$/D', (string)$fileId) !== 1
				|| (string)(int)$fileId !== (string)$fileId
			)
			{
				return;
			}

			(new AiLockService())->removeMatching((int)$fileId, $chatId, $operationId);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}
}
