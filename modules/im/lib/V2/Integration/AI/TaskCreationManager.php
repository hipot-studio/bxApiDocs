<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Payload\Prompt;
use Bitrix\AI\Quality;
use Bitrix\AI\Tuning\Defaults;
use Bitrix\Im\V2\Chat\OpenLineChat;
use Bitrix\Im\V2\Chat\OpenLineLiveChat;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\TranscriptionHandler;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Type\DateTime;

class TaskCreationManager
{
	public const CONTEXT_ID = 'chat_task_creation_from_transcription';
	private const MODULE_ID = 'im';
	private const PROMPT_CODE = 'task_extraction_prompt';
	private const DATE_FORMAT = 'Y/m/d H:i';

	private Message $message;
	private string $transcriptText;
	private int $fileId;
	private int $diskFileId;

	public function __construct(Message $message, string $transcriptText, int $fileId, int $diskFileId)
	{
		$this->message = $message;
		$this->transcriptText = $transcriptText;
		$this->fileId = $fileId;
		$this->diskFileId = $diskFileId;
	}

	public function createTask($data): void
	{
		if ($this->isEmptyMessage())
		{
			return;
		}

		Locator::getContext()->setUser($this->message->getAuthorId());
		$transcriptionHandler = ServiceLocator::getInstance()->get(TranscriptionHandler::class);

		$transcriptionHandler->handle($data, $this->message, $this->transcriptText);
	}

	public function sendAiQuery(): Result
	{
		$result = new Result();

		if (!$this->canSendQuery())
		{
			return $result;
		}

		$contextParams = [
			'fileId' => $this->fileId,
			'diskFileId' => $this->diskFileId,
			'messageId' => (int)$this->message->getId(),
		];

		$context = new Context(self::MODULE_ID, self::CONTEXT_ID);
		$context->setParameters($contextParams);

		$engine = $this->getEngine($context);
		if ($engine)
		{
			$engine
				->setPayload((new Prompt(self::PROMPT_CODE))->setMarkers([
					'transcript' => $this->transcriptText,
					'chat_users' => $this->getChatUsers($engine->requiresPersonalDataObfuscation()),
					'date_time' => $this->getCurrentTime(),
					'is_task_chat' => $this->convertBoolToInt($this->isTaskChat()),
					'anonymize_user_format' => $this->convertBoolToInt($engine->requiresPersonalDataObfuscation()),
				]))
				->setResponseJsonMode(true)
				->onError(function (Error $processingError) use(&$result) {
					$result->addError($processingError);
				})
				->completionsInQueue()
			;
		}

		(new AutoTaskStatus($this->message, Status::Search))->send();

		return $result;
	}

	protected function canSendQuery(): bool
	{
		if ($this->message->getParams()->isSet(Message\Params::FORWARD_ID))
		{
			return false;
		}

		$chat = $this->message->getChat();

		if (
			$chat instanceof OpenLineChat
			|| $chat instanceof OpenLineLiveChat
			|| $chat->getEntityType() === 'SUPPORT24_QUESTION' /** @see \Bitrix\ImBot\Bot\Support24::CHAT_ENTITY_TYPE */
			|| $chat->getEntityType() === 'NETWORK_DIALOG' /** @see \Bitrix\ImBot\Bot\NETWORK::CHAT_ENTITY_TYPE */
		)
		{
			return false;
		}

		if ($this->isEmptyMessage())
		{
			return false;
		}

		return true;
	}

	protected function isEmptyMessage(): bool
	{
		return $this->message->getMessageId() === null || $this->message->getChatId() === null;
	}

	protected function convertBoolToInt(bool $value): int
	{
		return $value ? 1 : 0;
	}

	protected function isTaskChat(): bool
	{
		return $this->message->getChat()->getEntityType() === 'TASKS_TASK';
	}

	protected function getCurrentTime(): string
	{
		$userTimeZone = User::getInstance($this->message->getAuthorId())->getTimezone();
		$timeZoneOffset = \CTimeZone::getTimezoneOffset($userTimeZone);
		$dateTime = DateTime::createFromTimestamp((new DateTime())->getTimestamp() + $timeZoneOffset);

		return $dateTime->format(self::DATE_FORMAT);
	}

	protected function getChatUsers(bool $anonymizeUserFormat = true): string
	{
		$result = [];
		$userIds = $this->message->getChat()->getRelations()->getUserIds();
		$userKeyConverter = new UserKeyConverter((int)$this->message->getChatId());

		foreach ($userIds as $userId)
		{
			$result[] = $anonymizeUserFormat
				? $userKeyConverter->getAnonymizedUserKey($userId)
				: $userKeyConverter->getUserKey($userId)
			;
		}

		return implode(', ', $result);
	}

	protected function getEngine(Context $context): ?Engine
	{
		$availableEngines = Defaults::getProviderSelectFieldParams(
			'text',
			new Quality(Quality::QUALITIES['chat_task_creation_from_transcription'])
		);

		return Engine::getByCode($availableEngines['default'] ?? '', $context);
	}
}
