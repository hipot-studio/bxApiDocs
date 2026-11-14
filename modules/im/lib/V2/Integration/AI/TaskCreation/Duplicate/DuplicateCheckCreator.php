<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation\Duplicate;

use Bitrix\AI\Context;
use Bitrix\AI\Payload\Prompt;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Integration\AI\TaskCreation\AbstractCreator;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Web\Json;

/** Second AI round: one request checks every extracted task that has candidates. */
class DuplicateCheckCreator extends AbstractCreator
{
	/** Public: the feature stays off while this prompt has not reached the portal. */
	public const PROMPT_CODE = 'task_duplicate_check_prompt';

	/**
	 * A task description is a mediumtext and the usual place for sums, contacts and internal
	 * links, so it leaves the portal cut to its opening. The verdict tells a duplicate from a
	 * new task by the object, the period, the context and the goal; the description carries the
	 * object and the period only when the title is generic, and for that its beginning is enough.
	 */
	private const MAX_DESCRIPTION_LENGTH = 500;

	public function __construct(
		Message $message,
		private readonly array $tasks,
		private readonly string $operationId,
	)
	{
		$this->message = $message;
	}

	public function sendAiQuery(): Result
	{
		$result = new Result();

		if ($this->isEmptyMessage() || empty($this->tasks))
		{
			return $result;
		}

		$userId = $this->getContext()->getUserId();

		$context = new Context(self::MODULE_ID, DuplicateCheckService::CONTEXT_ID);
		$context->setParameters([
			'messageId' => (int)$this->message->getMessageId(),
			'chatId' => (int)$this->message->getChatId(),
			'userId' => $userId,
			'operationId' => $this->operationId,
		]);

		$engine = $this->getEngine($context);
		if ($engine === null)
		{
			return $result->addError(new CopilotError(CopilotError::ENGINE_NOT_FOUND));
		}

		$engine
			->setPayload((new Prompt(static::PROMPT_CODE))->setMarkers([
				'tasks' => Json::encode($this->buildTasksPayload()),
				'date_time' => $this->getCurrentTime(),
			]))
			->setResponseJsonMode(true)
			->onError(static function (Error $processingError) use (&$result) {
				$result->addError($processingError);
			})
			->completionsInQueue()
		;

		return $result;
	}

	protected function buildTasksPayload(): array
	{
		$payload = [];
		foreach ($this->tasks as $taskKey => $task)
		{
			$payload[] = [
				'task_key' => (string)$taskKey,
				'new_task' => $task['newTask'] ?? [],
				'candidates' => $this->buildCandidatesPayload($task['candidates'] ?? []),
			];
		}

		return $payload;
	}

	private function buildCandidatesPayload(array $candidates): array
	{
		$payload = [];
		foreach ($candidates as $candidate)
		{
			$description = trim((string)($candidate['DESCRIPTION'] ?? ''));

			$payload[] = [
				'id' => (int)($candidate['ID'] ?? 0),
				'title' => (string)($candidate['TITLE'] ?? ''),
				'description' => mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH),
				'deadline' => (string)($candidate['DEADLINE'] ?? ''),
				'created_by' => (int)($candidate['CREATED_BY'] ?? 0),
				'responsible_id' => (int)($candidate['RESPONSIBLE_ID'] ?? 0),
			];
		}

		return $payload;
	}
}
