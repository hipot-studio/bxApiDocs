<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Integration\AI\TaskCreation\AbstractCreator;
use Bitrix\Im\V2\Integration\Tasks\Service\ChatTaskLinkService;
use Bitrix\Im\V2\Integration\Tasks\Service\CheckListService;
use Bitrix\Im\V2\Integration\Tasks\Service\ForwardService;
use Bitrix\Im\V2\Integration\Tasks\Service\TaskService;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\Mapper\CheckListMapper;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\Mapper\TaskMapper;
use Bitrix\Im\V2\Link\Task\TaskType;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Entity\Task;

class TranscribedTaskHandler
{
	private const LOGGER_ID = 'im.AiTaskCreation.SideEffect';

	/** Fields of `task_data` every consumer below declares as a string. */
	private const STRING_FIELDS = ['title', 'description', 'responsible', 'deadline'];

	/** Fields of `task_data` every consumer below declares as an array. */
	private const ARRAY_FIELDS = ['accomplices', 'checklists'];

	/** Items a check list needs to be one, @see self::withoutSingleItemCheckLists(). */
	private const MIN_CHECK_LIST_ITEMS = 2;

	public function __construct(
		private readonly TaskMapper $taskMapper,
		private readonly CheckListMapper $checkListMapper,
		private readonly TaskService $taskService,
		private readonly CheckListService $checkListService,
		private readonly ChatTaskLinkService $chatTaskLinkService,
		private readonly UnresolvedFieldsNotifier $unresolvedFieldsNotifier,
		private readonly ForwardService $forwardService,
		private readonly SkippedSourcesNotifier $skippedSourcesNotifier,
	)
	{
	}

	/**
	 * Task data for a task the model never recognized as an assignment: everything the model did
	 * fill in, plus the default title and an empty description (ALG-01).
	 *
	 * The participants are the exception. An answer without a task verdict is not a description of
	 * an assignment, so the users it names are no basis for assigning one - and this is the only
	 * path on which such an answer reaches the mapper at all. They are dropped, which leaves the
	 * task with the default responsible and no accomplices.
	 */
	public static function buildFallbackTaskData(array $taskData): array
	{
		$title = $taskData['title'] ?? '';
		$description = $taskData['description'] ?? '';

		unset($taskData['responsible'], $taskData['accomplices']);

		$taskData['title'] = (is_string($title) && trim($title) !== '')
			? $title
			: AbstractCreator::getDefaultTaskTitle()
		;
		$taskData['description'] = is_string($description) ? $description : '';

		return $taskData;
	}

	/**
	 * Task data of an assignment the model did recognize but left without a usable title: only the
	 * title falls back to the default one. Unlike buildFallbackTaskData() the participants stay -
	 * the verdict makes the users the model named a valid basis for the task.
	 *
	 * Only a manual launch normalizes the title this way, an automatic one keeps its outcomes.
	 */
	public static function withDefaultTitle(array $taskData): array
	{
		$title = $taskData['title'] ?? '';
		if (is_string($title) && trim($title) !== '')
		{
			return $taskData;
		}

		$taskData['title'] = AbstractCreator::getDefaultTaskTitle();

		return $taskData;
	}

	/**
	 * Task data without the check lists carrying fewer than two usable items. The prompt of the
	 * manual launch asks for at least two - a single step is not a check list - but the model
	 * breaks that rule now and then, and such a list reaches the card of the task as it was
	 * answered. The rule is held here and not by the prompt: every wording of it tried on the model
	 * broke a neighbouring case instead.
	 *
	 * The items are counted the way the consumer counts them
	 * (@see Mapper\CheckListMapper::convertFromTranscribedMessage()): an item without a title is
	 * skipped there, and a non-string one never becomes an item at all - so neither is counted
	 * here, otherwise the filter and the mapper would disagree on what a check list holds.
	 *
	 * The key itself goes away when nothing survives: an empty array would still reach the mapper
	 * as a field the model filled in.
	 *
	 * Only a manual launch normalizes the check lists this way, an automatic one keeps its outcomes.
	 */
	public static function withoutSingleItemCheckLists(array $taskData): array
	{
		if (!is_array($taskData['checklists'] ?? null))
		{
			return $taskData;
		}

		$checkLists = [];

		foreach ($taskData['checklists'] as $checkList)
		{
			if (self::countUsableCheckListItems($checkList) >= self::MIN_CHECK_LIST_ITEMS)
			{
				$checkLists[] = $checkList;
			}
		}

		if ($checkLists === [])
		{
			unset($taskData['checklists']);

			return $taskData;
		}

		$taskData['checklists'] = $checkLists;

		return $taskData;
	}

	private static function countUsableCheckListItems(mixed $checkList): int
	{
		if (!is_array($checkList) || !is_array($checkList['items'] ?? null))
		{
			return 0;
		}

		$usable = array_filter(
			$checkList['items'],
			static fn (mixed $item): bool => is_string($item) && !empty($item),
		);

		return count($usable);
	}

	/**
	 * @param MessageCollection|null $messagesToForward when set (multiselect flow), the whole
	 *        selection is forwarded to the task chat instead of the single source message
	 * @param bool $builtWithoutVerdict the task was assembled without a model verdict, so the AI
	 *        resolved no fields at all and the unresolved-fields notification would be misleading
	 * @param bool $isPartial part of the selected sources never made it into the task, so the
	 *        initiator is told the success is partial and the task chat gets a notice about it
	 */
	public function handle(
		array $taskData,
		Message $message,
		int $initiatorId,
		?MessageCollection $messagesToForward = null,
		bool $builtWithoutVerdict = false,
		bool $isPartial = false,
	): bool
	{
		if (empty($taskData) || !Loader::includeModule('tasks'))
		{
			return false;
		}

		if ($initiatorId <= 0)
		{
			return false;
		}

		$taskData = self::normalizeTaskData($taskData);

		$taskToAdd = $this->taskMapper->convertFromTranscribedMessage(
			$taskData,
			$message,
			$initiatorId,
			$messagesToForward !== null,
		);
		if ($taskToAdd === null)
		{
			return false;
		}

		try
		{
			$addedTask = $this->taskService->add($taskToAdd, $initiatorId);
		}
		catch (SystemException)
		{
			return false;
		}

		$this->runSideEffect(
			$addedTask,
			'checkLists',
			function () use ($addedTask, $taskData, $initiatorId)
			{
				$checkLists = $this->checkListMapper->convertFromTranscribedMessage($taskData['checklists'] ?? []);

				if (!empty($checkLists))
				{
					$this->checkListService->add($addedTask, $checkLists, $initiatorId);
				}
			},
		);

		$this->runSideEffect(
			$addedTask,
			'chatLink',
			function () use ($addedTask, $message, $initiatorId, $messagesToForward, $isPartial)
			{
				$this->chatTaskLinkService->linkFromMessage(
					taskId: $addedTask->getId(),
					message: $message,
					userId: $initiatorId,
					taskType: $this->getTaskType($addedTask, $messagesToForward !== null),
					isPartial: $isPartial,
				);
			},
		);

		if (!$builtWithoutVerdict)
		{
			$this->runSideEffect(
				$addedTask,
				'unresolvedFields',
				fn () => $this->unresolvedFieldsNotifier->notify($addedTask, $taskData, $initiatorId),
			);
		}

		if ($isPartial)
		{
			$this->runSideEffect(
				$addedTask,
				'skippedSources',
				fn () => $this->skippedSourcesNotifier->notify($addedTask, $initiatorId),
			);
		}

		$this->runSideEffect(
			$addedTask,
			'forward',
			function () use ($addedTask, $message, $initiatorId, $messagesToForward)
			{
				if (!FormV2Feature::isOn())
				{
					return;
				}

				$taskId = $addedTask->getId();

				$messagesToForward === null
					? $this->forwardService->forwardMessageToTask($message, $taskId, $initiatorId)
					: $this->forwardService->forwardMessagesToTask($messagesToForward, $taskId, $initiatorId);
			},
		);

		return true;
	}

	/**
	 * Brings `task_data` to the types its consumers declare. The answer of the model is external
	 * input of arbitrary shape, and the mapper, the participant resolver and the check list mapper
	 * all run under declare(strict_types=1): a `{"deadline": 5}` would reach a string parameter and
	 * raise a TypeError outside the try below, which guards TaskService::add() alone - the task
	 * would fail without a terminal status and the panel of the initiator would hang. A field of
	 * the wrong type is therefore treated as a field the model did not fill in.
	 *
	 * Only the fields the answer carries are touched: an absent field must stay absent, its
	 * default belongs to the consumer (and to the two title fallbacks above).
	 */
	private static function normalizeTaskData(array $taskData): array
	{
		foreach (self::STRING_FIELDS as $field)
		{
			if (array_key_exists($field, $taskData) && !is_string($taskData[$field]))
			{
				$taskData[$field] = '';
			}
		}

		foreach (self::ARRAY_FIELDS as $field)
		{
			if (array_key_exists($field, $taskData) && !is_array($taskData[$field]))
			{
				$taskData[$field] = [];
			}
		}

		if (isset($taskData['accomplices']))
		{
			// the resolver reads every element as a participant key, so a non-string one is noise
			$taskData['accomplices'] = array_values(array_filter($taskData['accomplices'], 'is_string'));
		}

		return $taskData;
	}

	/**
	 * Runs a side effect of an already created task. The outcome of the operation is decided by
	 * TaskService::add(): once it returned a task, neither the chat card nor the terminal status
	 * may be cancelled by a later failure, so such a failure only degrades the result and is logged.
	 */
	private function runSideEffect(Task $task, string $step, callable $action): void
	{
		try
		{
			$action();
		}
		catch (\Throwable $exception)
		{
			(new LoggerFactory())->createById(self::LOGGER_ID)?->error(
				'AI task side effect failed',
				[
					'step' => $step,
					'taskId' => $task->getId(),
					'exception' => $exception->getMessage(),
				],
			);
		}
	}

	private function getTaskType(Task $task, bool $isMultipleMessages): TaskType
	{
		// multiselect context wins over the anchor message type: the anchor can be
		// a voice/video note, but the task is still a multiple-messages auto task
		if ($isMultipleMessages)
		{
			return TaskType::MultipleMessagesAutoTask;
		}

		if ($task->scenarios?->contains(Task\Scenario::Voice))
		{
			return TaskType::VoiceNoteAutoTask;
		}

		if ($task->scenarios?->contains(Task\Scenario::Video))
		{
			return TaskType::VideoNoteAutoTask;
		}

		return TaskType::SingleMessageAutoTask;
	}
}
