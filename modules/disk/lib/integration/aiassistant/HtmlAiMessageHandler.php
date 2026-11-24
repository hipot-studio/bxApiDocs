<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant;

use Bitrix\AiAssistant\Trigger\Repository\ChatMapRepository;
use Bitrix\AiAssistant\Trigger\Repository\TriggerRepository;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Integration\AiAssistant\Trigger\Action\FileChatTrigger;
use Bitrix\Disk\Internal\Service\HtmlEditor\AiLockService;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class HtmlAiMessageHandler
{
	public function __construct(
		private readonly ?AiLockService $locks = null,
		private readonly ?TriggerRepository $triggers = null,
		private readonly ?ChatMapRepository $chats = null,
	)
	{
	}

	public static function onBeforeChatMessageAdd(array $fields): array|bool
	{
		return (new self())->handle($fields);
	}

	public function handle(array $fields): array|bool
	{
		if (($fields['PARAMS']['COPILOT_PAGE_CONTEXT']['custom']['disk']['file']['htmlEditor'] ?? false) !== true)
		{
			return true;
		}

		$fileId = $fields['PARAMS']['COPILOT_PAGE_CONTEXT']['custom']['disk']['file']['objectId'] ?? null;
		if (
			(!is_int($fileId) && !is_string($fileId))
			|| preg_match('/^[1-9][0-9]*$/D', (string)$fileId) !== 1
			|| (string)(int)$fileId !== (string)$fileId
			|| !Loader::includeModule('aiassistant')
		)
		{
			return true;
		}

		try
		{
			$userId = (int)($fields['FROM_USER_ID'] ?? 0);
			$chatId = (int)($fields['TO_CHAT_ID'] ?? 0);
			$triggers = $this->triggers ?? ServiceLocator::getInstance()->get(TriggerRepository::class);
			$triggerId = $triggers->getByCode(FileChatTrigger::getCode());
			if (
				$userId <= 0 || $chatId <= 0 || $triggerId === null
				|| ($this->chats ?? new ChatMapRepository())->findChatId($userId, $triggerId, (string)$fileId) !== $chatId
			)
			{
				return true;
			}
			$file = File::loadById((int)$fileId);
			if ($file && !HtmlViewerPolicy::isViewableFile($file))
			{
				return true;
			}
			$operationId = $fields['TEMPLATE_ID'] ?? null;
			if (!is_string($operationId) || !AiLockService::isValidOperationId($operationId))
			{
				return $this->veto(AiLockService::ERROR_INVALID_OPERATION);
			}
			$result = ($this->locks ?? new AiLockService())->lockForOperation((int)$fileId, $userId, $chatId, $operationId);

			return $result->isSuccess() ? true : $this->veto((string)$result->getError()?->getCode());
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->veto(AiLockService::ERROR_UNAVAILABLE);
		}
	}

	private function veto(string $reason): array
	{
		return [
			'result' => false,
			'reason' => $reason,
		];
	}
}
