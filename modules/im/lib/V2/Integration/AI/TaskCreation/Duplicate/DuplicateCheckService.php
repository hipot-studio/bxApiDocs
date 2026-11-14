<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation\Duplicate;

use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Im\V2\Integration\AI\TaskCreation\MultipleMessagesCreator;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\DeferredTaskCreator;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\ParticipantResolver;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\TaskCreationOutcome;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\TranscribedTaskHandler;
use Bitrix\Im\V2\Logger;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Public\Service\DuplicateCandidateService;

/**
 * Duplicate check of an extracted task: the shortlist is selected in tasks, the verdict is asked
 * from the model, and a task the model calls a duplicate is held back until the initiator decides.
 *
 * Fail-open by design: no shortlist, no engine, a broken response or a failed request must leave
 * the flow exactly as it was before the check existed.
 */
class DuplicateCheckService
{
	public const CONTEXT_ID = 'chat_task_duplicate_check';

	private const SEARCH_WORDS_FIELD = 'search_words';
	private const MAX_CANDIDATES = 5;
	private const MAX_CHECKED_TASKS = 3;
	private const MAX_CANDIDATE_DESCRIPTION_LENGTH = 500;

	private const LOG_TAG = 'ai_task_duplicate_check';
	private array $checkedTasks = [];

	public function __construct(
		private readonly PendingStorage $storage = new PendingStorage(),
		private readonly DuplicateMessageService $messageService = new DuplicateMessageService(),
		private readonly Restriction $restriction = new Restriction(),
		private ?DeferredTaskCreator $taskCreator = null,
	)
	{
	}

	/**
	 * @param string $title title of the task about to be created
	 * @param int|null $responsibleId who the task is about to be assigned to
	 *
	 * @return bool true when the task must NOT be created now: it is parked and waits for the verdict
	 */
	public function checkBeforeCreate(
		array $taskData,
		string $title,
		?int $responsibleId,
		Message $message,
		string $sourceText,
		int $initiatorId,
		array $selectionIds = [],
		bool $builtWithoutVerdict = false,
		bool $isPartial = false,
	): bool
	{
		try
		{
			return $this->dispatch(
				$taskData,
				$title,
				$responsibleId,
				$message,
				$sourceText,
				$initiatorId,
				$selectionIds,
				$builtWithoutVerdict,
				$isPartial,
			);
		}
		catch (\Throwable $exception)
		{
			(new Logger(self::LOG_TAG))->logThrowable($exception);

			return false;
		}
	}

	/** Closes the batch and sends one verdict request for every parked task in it. */
	public function finalizeBatch(Message $message, int $initiatorId, int $createdBeforeCheck = 0): void
	{
		$anchorMessageId = (int)$message->getMessageId();

		try
		{
			$params = $this->storage->finalizeBatch($anchorMessageId, $initiatorId, $createdBeforeCheck);
			if ($params === null)
			{
				return;
			}
			$operationId = (string)($params['operationId'] ?? '');
			if ($operationId === '' || empty($params['tasks']))
			{
				$this->createWithoutVerdict($anchorMessageId);

				return;
			}

			$result = $this->askForVerdict(
				$message,
				$params['tasks'],
				$operationId,
				$initiatorId,
			);
			if ($result->isSuccess())
			{
				return;
			}

			(new Logger(self::LOG_TAG))->logErrors($result->getErrorCollection());
		}
		catch (\Throwable $exception)
		{
			(new Logger(self::LOG_TAG))->logThrowable($exception);
		}

		$this->createWithoutVerdict($anchorMessageId);
	}

	public function handleVerdict(array $data, array $parameters): void
	{
		$verdicts = $this->normalizeVerdicts($data, $parameters);
		if ($verdicts !== null)
		{
			$this->applyToPending($parameters, $verdicts);
		}
	}

	/**
	 * A failed job releases the task instead of holding it: a missing verdict must cost the user
	 * nothing but a possible duplicate.
	 */
	public function handleFailedVerdict(array $parameters = []): void
	{
		$this->applyToPending($parameters, []);
	}

	/**
	 * "Create anyway" of the initiator: claims the parked batch and creates everything in it.
	 *
	 * @param int $decisionMessageId id of the message the button was pressed on, as the client saw
	 *        it - the batch is only taken when it is the message this very record published
	 *
	 * @return bool true when this call took the batch and created every task in it
	 */
	public function createAnyway(int $anchorMessageId, int $userId, int $decisionMessageId): bool
	{
		try
		{
			// a record that has not published its button yet stores 0 and must match nothing
			if ($decisionMessageId <= 0)
			{
				return false;
			}

			$captured = $this->storage->capture($anchorMessageId, $userId, $decisionMessageId);
			if ($captured === null)
			{
				return false;
			}
			$anchor = new Message($anchorMessageId);
			if (!$this->hasAccessToCapturedMessages($anchorMessageId, $decisionMessageId, $captured, $userId))
			{
				$this->storage->drop($anchorMessageId);
				$this->messageService->showResult($decisionMessageId);
				$this->sendTerminalStatus(
					$anchor,
					$userId,
					$this->getTotalCreated($captured, 0) > 0,
					$this->isPartialResult($captured),
				);

				return false;
			}
			$isSelectionComplete = $this->noteSelection($captured);
			$tasks = $captured['tasks'] ?? [];
			$created = 0;
			foreach ($tasks as $taskKey => $task)
			{
				$created += $this->releaseTask(
					$anchorMessageId,
					(string)$taskKey,
					$task,
					$anchor,
					$captured,
					$userId,
				) ? 1 : 0;
			}
			$total = count($tasks);
			$this->notifyIncompleteSelection($anchorMessageId, $userId, $created, $isSelectionComplete);
			$this->messageService->showResult($decisionMessageId);
			$this->sendTerminalStatus(
				$anchor,
				$userId,
				$this->getTotalCreated($captured, $created) > 0,
				$this->isPartialResult($captured),
			);

			return $created === $total;
		}
		catch (\Throwable $exception)
		{
			(new Logger(self::LOG_TAG))->logThrowable($exception);

			return false;
		}
	}

	private function hasAccessToCapturedMessages(
		int $anchorMessageId,
		int $decisionMessageId,
		array $params,
		int $userId,
	): bool
	{
		$messageIds = [$decisionMessageId, $anchorMessageId];
		foreach ($params['selectionIds'] ?? [] as $messageId)
		{
			$messageIds[] = (int)$messageId;
		}

		foreach (array_unique($messageIds) as $messageId)
		{
			if ($messageId <= 0 || !$this->canAccessMessage($messageId, $userId))
			{
				return false;
			}
		}

		return true;
	}

	protected function canAccessMessage(int $messageId, int $userId): bool
	{
		return (new Message($messageId))->checkAccess($userId)->isSuccess();
	}

	/** Creates parked tasks once when the verdict request could not be started. */
	private function createWithoutVerdict(int $anchorMessageId): void
	{
		$params = $this->storage->take($anchorMessageId);
		if ($params === null)
		{
			return;
		}

		$initiatorId = (int)($params['initiatorId'] ?? 0);
		if ($initiatorId <= 0)
		{
			$this->storage->drop($anchorMessageId);

			return;
		}

		$message = new Message($anchorMessageId);
		$isSelectionComplete = $this->noteSelection($params);
		$created = 0;
		foreach ($params['tasks'] ?? [] as $taskKey => $task)
		{
			$created += $this->releaseTask(
				$anchorMessageId,
				(string)$taskKey,
				$task,
				$message,
				$params,
				$initiatorId,
			) ? 1 : 0;
		}
		$this->notifyIncompleteSelection($anchorMessageId, $initiatorId, $created, $isSelectionComplete);

		$this->sendTerminalStatus(
			$message,
			$initiatorId,
			$this->getTotalCreated($params, $created) > 0,
			$this->isPartialResult($params),
		);
	}

	private function dispatch(
		array $taskData,
		string $title,
		?int $responsibleId,
		Message $message,
		string $sourceText,
		int $initiatorId,
		array $selectionIds,
		bool $builtWithoutVerdict,
		bool $isPartial,
	): bool
	{
		if (!$this->restriction->isAiTaskDedupActive() || !Loader::includeModule('tasks'))
		{
			return false;
		}

		$searchWords = $this->getSearchWords($taskData, $title);
		if ($searchWords === '')
		{
			return false;
		}

		$anchorMessageId = (int)$message->getMessageId();
		if (!$this->takeCheckBudget($anchorMessageId))
		{
			return false;
		}

		$candidates = $this->findCandidates($searchWords, $title, $responsibleId, $initiatorId);

		if (empty($candidates))
		{
			return false;
		}

		// parking first: a verdict that comes back before the task is parked has nothing to release
		$taskKey = $this->park(
			$anchorMessageId,
			$taskData,
			$title,
			$candidates,
			$sourceText,
			$initiatorId,
			$this->resolveExplicitResponsibleId($taskData, $message),
			$selectionIds,
			$builtWithoutVerdict,
			$isPartial,
		);
		if ($taskKey === null)
		{
			return false;
		}

		return true;
	}

	private function resolveExplicitResponsibleId(array $taskData, Message $message): ?int
	{
		$participantIdentifier = $taskData['responsible'] ?? null;
		if (!is_string($participantIdentifier) || $participantIdentifier === '')
		{
			return null;
		}

		return (new ParticipantResolver())
			->resolveAccomplices([$participantIdentifier], $message->getChat())
			->getFirstEntity()?->id
		;
	}

	/**
	 * Two searches, merged - neither is a fallback for the other, because a non-empty result of one
	 * says nothing about the other.
	 *
	 * The index matches all words at once and only by prefix, so one word form the existing task
	 * does not have empties the result: the core comes from the model, and the form it picked does
	 * not prefix-match the form stored in the task. Such a core still matches other tasks and fills
	 * the shortlist with the wrong ones. The title is the model's own wording of the same work, so
	 * it goes first and catches what dedup exists for - the same request repeated in the same words.
	 */
	private function findCandidates(
		string $searchWords,
		string $title,
		?int $responsibleId,
		int $initiatorId,
	): array
	{
		$queries = array_unique(array_filter([trim($title), $searchWords]));

		$candidates = [];
		foreach ($queries as $query)
		{
			foreach ($this->searchCandidates($query, $initiatorId, $responsibleId) as $candidate)
			{
				$candidates[(int)$candidate['ID']] ??= $candidate;
			}

			if (count($candidates) >= self::MAX_CANDIDATES)
			{
				break;
			}
		}

		return array_slice(array_values($candidates), 0, self::MAX_CANDIDATES);
	}

	/** One shortlist query to the task list, the only place the check reaches into tasks. */
	protected function searchCandidates(string $query, int $initiatorId, ?int $responsibleId): array
	{
		return (new DuplicateCandidateService())
			->findCandidates($query, $initiatorId, $responsibleId, self::MAX_CANDIDATES)
		;
	}

	/** The second AI round, the only place the check reaches into the AI queue. */
	protected function askForVerdict(
		Message $message,
		array $tasks,
		string $operationId,
		int $initiatorId,
	): Result
	{
		return (new DuplicateCheckCreator($message, $tasks, $operationId))
			->setContextUser($initiatorId)
			->sendAiQuery()
		;
	}

	private function takeCheckBudget(int $anchorMessageId): bool
	{
		$checked = $this->checkedTasks[$anchorMessageId] ?? 0;
		if ($checked >= self::MAX_CHECKED_TASKS)
		{
			return false;
		}

		$this->checkedTasks[$anchorMessageId] = $checked + 1;

		return true;
	}

	private function park(
		int $anchorMessageId,
		array $taskData,
		string $title,
		array $candidates,
		string $sourceText,
		int $initiatorId,
		?int $responsibleId,
		array $selectionIds,
		bool $builtWithoutVerdict,
		bool $isPartial,
	): ?string
	{
		return $this->storage->appendTask(
			$anchorMessageId,
			[
				'initiatorId' => $initiatorId,
				'sourceTextHash' => sha1($sourceText),
				'selectionIds' => array_values(array_map('intval', $selectionIds)),
			],
			[
				'taskData' => $taskData,
				'builtWithoutVerdict' => $builtWithoutVerdict,
				'isPartial' => $isPartial,
				'title' => $title,
				'newTask' => [
					'title' => $title,
					'description' => (string)($taskData['description'] ?? ''),
					'deadline' => (string)($taskData['deadline'] ?? ''),
					'responsible_id' => $responsibleId,
				],
				'candidates' => $this->prepareCandidates($candidates),
				'verdict' => null,
			],
		);
	}

	private function normalizeVerdicts(array $data, array $parameters): ?array
	{
		$anchorMessageId = (int)($parameters['messageId'] ?? 0);
		$operationId = (string)($parameters['operationId'] ?? '');
		$initiatorId = (int)($parameters['userId'] ?? 0);
		$params = $anchorMessageId > 0 ? $this->storage->read($anchorMessageId) : null;

		if (
			$params === null
			|| $operationId === ''
			|| ($params['operationId'] ?? '') !== $operationId
			|| (int)($params['initiatorId'] ?? 0) !== $initiatorId
		)
		{
			return null;
		}

		$answers = $data['verdicts'] ?? [];
		$answers = is_array($answers) ? $answers : [];
		$answersByTask = [];
		foreach ($answers as $answer)
		{
			if (is_array($answer) && isset($answer['task_key']))
			{
				$answersByTask[(string)$answer['task_key']] = $answer;
			}
		}

		$verdicts = [];
		foreach ($params['tasks'] ?? [] as $taskKey => $task)
		{
			$answer = $answersByTask[(string)$taskKey] ?? [];
			$matchedTaskId = (int)($answer['task_id'] ?? 0);
			$candidateIds = array_map('intval', array_column($task['candidates'] ?? [], 'ID'));
			$isDuplicate = ($answer['verdict'] ?? '') === PendingStorage::VERDICT_DUPLICATE
				&& in_array($matchedTaskId, $candidateIds, true)
			;

			$verdicts[(string)$taskKey] = [
				'verdict' => $isDuplicate ? PendingStorage::VERDICT_DUPLICATE : PendingStorage::VERDICT_NEW,
				'matchedTaskId' => $isDuplicate ? $matchedTaskId : 0,
				'reason' => is_string($answer['reason'] ?? null) ? $answer['reason'] : '',
			];
		}

		return $verdicts;
	}

	private function applyToPending(array $parameters, array $verdicts): void
	{
		$anchorMessageId = (int)($parameters['messageId'] ?? 0);
		$operationId = (string)($parameters['operationId'] ?? '');
		$initiatorId = (int)($parameters['userId'] ?? 0);
		if ($anchorMessageId <= 0 || $operationId === '' || $initiatorId <= 0)
		{
			return;
		}

		try
		{
			$params = $this->storage->applyVerdicts(
				$anchorMessageId,
				$operationId,
				$initiatorId,
				$verdicts,
			);
			if ($params !== null)
			{
				$this->resolve($anchorMessageId, $params);
			}
		}
		catch (\Throwable $exception)
		{
			(new Logger(self::LOG_TAG))->logThrowable($exception);
		}
	}

	private function resolve(int $anchorMessageId, array $params): void
	{
		$message = new Message($anchorMessageId);
		$initiatorId = (int)($params['initiatorId'] ?? 0);
		if ($initiatorId <= 0)
		{
			$this->storage->drop($anchorMessageId);

			return;
		}

		$isSelectionComplete = $this->noteSelection($params);

		$duplicates = [];
		$created = 0;
		foreach ($params['tasks'] ?? [] as $key => $task)
		{
			if (($task['verdict'] ?? '') === PendingStorage::VERDICT_DUPLICATE)
			{
				$duplicates[$key] = $task;

				continue;
			}

			$created += $this->releaseTask(
				$anchorMessageId,
				(string)$key,
				$task,
				$message,
				$params,
				$initiatorId,
			) ? 1 : 0;
		}

		if (empty($duplicates))
		{
			$this->notifyIncompleteSelection($anchorMessageId, $initiatorId, $created, $isSelectionComplete);
			$this->sendTerminalStatus(
				$message,
				$initiatorId,
				$this->getTotalCreated($params, $created) > 0,
				$this->isPartialResult($params),
			);

			return;
		}

		$rows = $this->buildDuplicateRows($duplicates);
		$decisionMessageId = $this->messageService->send($message, $initiatorId, $rows);
		if ($decisionMessageId === null)
		{
			// there is no way to ask, so the tasks are created as if the check never happened
			foreach ($duplicates as $key => $task)
			{
				$created += $this->releaseTask(
					$anchorMessageId,
					(string)$key,
					$task,
					$message,
					$params,
					$initiatorId,
				) ? 1 : 0;
			}

			$this->notifyIncompleteSelection($anchorMessageId, $initiatorId, $created, $isSelectionComplete);
			$this->sendTerminalStatus(
				$message,
				$initiatorId,
				$this->getTotalCreated($params, $created) > 0,
				$this->isPartialResult($params),
			);

			return;
		}

		if (!$this->storage->keepForDecision(
			$anchorMessageId,
			$decisionMessageId,
			$this->getTotalCreated($params, $created),
		))
		{
			$this->messageService->showResult($decisionMessageId);
			$this->notifyIncompleteSelection($anchorMessageId, $initiatorId, $created, $isSelectionComplete);
			$this->sendTerminalStatus(
				$message,
				$initiatorId,
				$this->getTotalCreated($params, $created) > 0,
				$this->isPartialResult($params),
			);

			return;
		}

		$this->notifyIncompleteSelection($anchorMessageId, $initiatorId, $created, $isSelectionComplete);
	}

	/**
	 * Creation of a task the record still holds. The pending item is removed first because the
	 * original flow attempts creation once, and a failed outcome may mean the task was already saved.
	 */
	private function releaseTask(
		int $anchorMessageId,
		string $taskKey,
		array $task,
		Message $message,
		array $params,
		int $initiatorId,
	): bool
	{
		if (!$this->storage->removeTask($anchorMessageId, $taskKey))
		{
			return false;
		}

		return $this->createTask($task, $message, $params, $initiatorId);
	}

	private function createTask(array $task, Message $message, array $params, int $initiatorId): bool
	{
		try
		{
			return $this->runCreation($task, $message, $params, $initiatorId) === TaskCreationOutcome::Created;
		}
		catch (\Throwable $exception)
		{
			// the batch is already taken, so one task that throws must not take the rest with it
			(new Logger(self::LOG_TAG))->logThrowable($exception);

			return false;
		}
	}

	private function runCreation(array $task, Message $message, array $params, int $initiatorId): TaskCreationOutcome
	{
		$selectionIds = array_values(array_map('intval', $params['selectionIds'] ?? []));

		// the user context is a static of the module, so the substitution lives exactly as long as
		// the deferred creation it belongs to
		$previousContext = Locator::getContext();
		Locator::setContext((new Context())->setUserId($initiatorId));

		try
		{
			return $this->getTaskCreator()->createDeferred(
				$task['taskData'] ?? [],
				$message,
				'',
				$initiatorId,
				empty($selectionIds) ? null : new MessageCollection($selectionIds),
				(bool)($task['builtWithoutVerdict'] ?? false),
				(bool)($task['isPartial'] ?? false),
			);
		}
		finally
		{
			Locator::setContext($previousContext);
		}
	}

	/**
	 * Resolved on first use, never in the constructor: the creator holds this service back, so
	 * building it eagerly would recurse.
	 */
	private function getTaskCreator(): DeferredTaskCreator
	{
		return $this->taskCreator ??= ServiceLocator::getInstance()->get(TranscribedTaskHandler::class);
	}

	private function sendTerminalStatus(
		Message $message,
		int $initiatorId,
		bool $hasCreated,
		bool $isPartial,
	): void
	{
		(new AutoTaskStatus(
			$message,
			$initiatorId,
			$hasCreated ? Status::TaskCreationCompleted : Status::NotFound,
			true,
			$isPartial,
		))->send();
	}

	private function isPartialResult(array $params): bool
	{
		foreach ($params['tasks'] ?? [] as $task)
		{
			if (($task['isPartial'] ?? false) === true)
			{
				return true;
			}
		}

		return false;
	}

	private function getTotalCreated(array $params, int $createdAfterCheck): int
	{
		$createdBeforeDecision = $params['createdBeforeDecision'] ?? $params['createdBeforeCheck'] ?? 0;

		return max(0, (int)$createdBeforeDecision) + $createdAfterCheck;
	}

	private function notifyIncompleteSelection(
		int $anchorMessageId,
		int $initiatorId,
		int $created,
		bool $isSelectionComplete,
	): void
	{
		if ($created > 0 && !$isSelectionComplete)
		{
			$this->messageService->notifySelectionIncomplete(
				$anchorMessageId,
				$initiatorId,
				(string)Loc::getMessage('IM_AI_TASK_DEDUP_RESULT_CREATED_WITHOUT_DELETED'),
			);
		}
	}

	private function buildDuplicateRows(array $duplicates): array
	{
		$rows = [];
		foreach ($duplicates as $task)
		{
			$matchedTaskId = (int)($task['matchedTaskId'] ?? 0);

			$rows[] = [
				'title' => (string)($task['title'] ?? ''),
				'matchedTitle' => $this->getCandidateTitle($task, $matchedTaskId),
				'matchedTaskId' => $matchedTaskId,
				'reason' => (string)($task['reason'] ?? ''),
			];
		}

		return $rows;
	}

	private function getCandidateTitle(array $task, int $matchedTaskId): string
	{
		foreach ($task['candidates'] ?? [] as $candidate)
		{
			if ((int)($candidate['ID'] ?? 0) === $matchedTaskId)
			{
				return (string)($candidate['TITLE'] ?? '');
			}
		}

		return '';
	}

	private function prepareCandidates(array $candidates): array
	{
		$result = [];
		foreach ($candidates as $candidate)
		{
			$result[] = [
				'ID' => (int)($candidate['ID'] ?? 0),
				'TITLE' => (string)($candidate['TITLE'] ?? ''),
				'DESCRIPTION' => mb_substr(
					trim((string)($candidate['DESCRIPTION'] ?? '')),
					0,
					self::MAX_CANDIDATE_DESCRIPTION_LENGTH,
				),
				'CREATED_BY' => (int)($candidate['CREATED_BY'] ?? 0),
				'RESPONSIBLE_ID' => (int)($candidate['RESPONSIBLE_ID'] ?? 0),
				'DEADLINE' => isset($candidate['DEADLINE']) ? (string)$candidate['DEADLINE'] : null,
			];
		}

		return $result;
	}

	/**
	 * Search core comes from the model: it knows which words carry the meaning of the
	 * request. The title is a fallback for portals whose extraction prompt has no
	 * search_words yet.
	 */
	private function getSearchWords(array $taskData, string $title): string
	{
		$rawWords = $taskData[self::SEARCH_WORDS_FIELD] ?? '';
		if (is_string($rawWords))
		{
			$searchWords = trim($rawWords);
		}
		elseif (is_array($rawWords))
		{
			$words = [];
			foreach ($rawWords as $word)
			{
				if (is_string($word) && trim($word) !== '')
				{
					$words[] = trim($word);
				}
			}

			$searchWords = implode(' ', $words);
		}
		else
		{
			$searchWords = '';
		}

		return $searchWords === '' ? trim($title) : $searchWords;
	}

	/**
	 * The selection travels with the batch and may be waited on for hours, so a message of it can
	 * be deleted before the task is created. The live path refuses to build a task from what is
	 * left of a selection (ERR-015), and here that would be wrong twice over: the model read the
	 * selection while it was whole and the task is built from what it extracted then, and refusing
	 * would lose the assignment - which this check may never do. So the task is created, and what
	 * the initiator loses is a forwarded message, not the errand. What must not happen is losing
	 * it silently.
	 *
	 * @return bool false when the selection is no longer whole
	 */
	private function noteSelection(array $params): bool
	{
		$selectionIds = array_values(array_map('intval', $params['selectionIds'] ?? []));
		if (empty($selectionIds))
		{
			return true;
		}

		return MultipleMessagesCreator::isSelectionComplete(
			$selectionIds,
			new MessageCollection($selectionIds),
		);
	}
}
