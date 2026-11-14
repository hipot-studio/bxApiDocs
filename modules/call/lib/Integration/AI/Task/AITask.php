<?php

namespace Bitrix\Call\Integration\AI\Task;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Type\DateTime;
use Bitrix\Im\V2\Chat;
use Bitrix\Call\Model\EO_CallAITask;
use Bitrix\Call\Model\CallAITaskTable;
use Bitrix\Call\Integration\AI\Outcome;
use Bitrix\Call\Integration\AI\SenseType;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Entity;

/**
 * @see EO_CallAITask
 * @method string|null getLanguageId()
 * @method AITask setLanguageId(string $languageId)
 * @method string|null getType()
 * @method AITask setType(string $type)
 * @method int|null getOutcomeId()
 * @method AITask setOutcomeId(int $outcomeId)
 * @method int|null getTrackId()
 * @method AITask setTrackId(int $trackId)
 * @method int|null getCallId()
 * @method AITask setCallId(int $callId)
 * @method string|null getStatus()
 * @method AITask setStatus(string $status)
 * @method string|null getHash()
 * @method AITask setHash(string $status)
 * @method string|null getErrorMessage()
 * @method AITask setErrorMessage(string $status)
 * @method string|null getErrorCode()
 * @method AITask setErrorCode(string $status)
 * @method DateTime getDateCreate()
 * @method AITask setDateCreate(DateTime $dateCreate)
 * @method DateTime|null getDateFinished()
 * @method AITask setDateFinished(DateTime $dateFinished)
 * @method \Bitrix\Call\Model\EO_Call fillCall()
 * @method \Bitrix\Call\Track fillTrack()
 * @method \Bitrix\Call\Integration\AI\Outcome fillOutcome()
 * @method \Bitrix\Main\ORM\Data\Result save()
 * @method \Bitrix\Main\ORM\Data\Result delete()
 *
 * @internal
 */
abstract class AITask
{
	public const
		STATUS_READY = 'ready',
		STATUS_PENDING = 'pending',
		STATUS_FINISHED = 'finished',
		STATUS_FAILED = 'failed'
	;

	protected ?EO_CallAITask $task = null;


	/**
	 * @param EO_CallAITask|null $source
	 */
	public function __construct(?EO_CallAITask $source = null)
	{
		if ($source instanceof EO_CallAITask)
		{
			$this->task = $source;
		}
		else
		{
			$this->task = new EO_CallAITask();
			$this->task->setType($this->getAISenseType());
		}
	}

	public static function loadById(int $taskId): ?self
	{
		$row = CallAITaskTable::getById($taskId)?->fetchObject();
		if ($row)
		{
			return static::buildBySource($row);
		}

		return null;
	}

	public static function buildBySource(EO_CallAITask $source): self
	{
		$taskSenseType = SenseType::tryFrom($source->getType());
		if ($taskSenseType)
		{
			$class = $taskSenseType->getTaskClass();

			return new $class($source);
		}

		return new class($source) extends AITask
		{
			public function setPayload($payload): AITask
			{
				return $this;
			}
			public function getAIPayload(): Result
			{
				return new Result;
			}
			public function getAIEngineCategory(): string
			{
				return '';
			}
			public function getAISenseType(): string
			{
				return '';
			}
			public function allowNotifyTaskFailed(): bool
			{
				return true;
			}
			public function getVersion(): int
			{
				return 1;
			}
		};
	}

	public static function getTasksForCall(int $callId): array
	{
		// Create subquery to get MAX(ID) per TYPE for this CALL_ID
		$subQuery = CallAITaskTable::query()
			->addSelect('TYPE')
			->addSelect(new ExpressionField('MAX_ID', 'MAX(ID)'))
			->addFilter('=CALL_ID', $callId)
			->setGroup(['TYPE']);

		// Main query - get full task objects by joining with subquery
		$query = CallAITaskTable::query()
			->addSelect('*')
			->registerRuntimeField(new Reference('SUB',
				Entity::getInstanceByQuery($subQuery),
				[
					'=this.ID' => 'ref.MAX_ID',
				],
				['join_type' => 'INNER']
			)
		);

		$taskObjects = $query->exec()->fetchCollection();
		if ($taskObjects->isEmpty())
		{
			return [];
		}

		$result = [];
		foreach ($taskObjects as $taskObj)
		{
			$result[$taskObj->getType()] = self::buildBySource($taskObj);
		}

		return $result;
	}

	public static function getTaskForCall(int $callId, SenseType $senseType): ?self
	{
		$task = CallAITaskTable::getList([
			'filter' => [
				'=CALL_ID' => $callId,
				'=TYPE' => $senseType->value,
			],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])?->fetchObject();
		if ($task)
		{
			return self::buildBySource($task);
		}

		return null;
	}

	/**
	 * Whether an analysis of this very track is already running.
	 *
	 * Asked by track and status, not as "is the newest task of the track pending": a restart
	 * adds a task to a track that already has one, so a finished newer task can stand in front
	 * of a pending older one.
	 */
	public static function hasPendingTaskForTrack(int $trackId, SenseType $senseType): bool
	{
		$pending = CallAITaskTable::query()
			->setSelect(['ID'])
			->where('TRACK_ID', $trackId)
			->where('TYPE', $senseType->value)
			->where('STATUS', self::STATUS_PENDING)
			->setLimit(1)
			->exec()
		;

		return $pending->getSelectedRowsCount() > 0;
	}

	/**
	 * Id of the newest task of the call, 0 for a call that has none.
	 *
	 * The boundary of a generation is read from here: a restart that erases the previous analysis
	 * reads it once before it creates a task of its own, and everything at or below it belongs to
	 * the generation being replaced {@see \Bitrix\Call\Integration\AI\CallAIService::restartWithPurge()}.
	 */
	public static function getLatestTaskId(int $callId): int
	{
		$row = CallAITaskTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $callId)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->exec()
			->fetch()
		;

		return (int)($row['ID'] ?? 0);
	}

	/**
	 * Ids of the tasks of one type of the call that are not newer than the boundary of the
	 * generation.
	 *
	 * By the sequence of the tasks and not by a date: two tasks of one attempt can share a second,
	 * while the id of a row is the order it was written in.
	 *
	 * Ids and not objects, for the reason the results are read as ids
	 * {@see \Bitrix\Call\Integration\AI\Outcome::getOutcomeIdsOfSenseUpTo()}: the one caller
	 * erases them, and building a task object per row of the history of the call is work done
	 * inside the lock of a restart to no end.
	 *
	 * @return int[] oldest first
	 */
	public static function getTaskIdsOfSenseUpTo(int $callId, SenseType $senseType, int $boundaryId): array
	{
		if ($boundaryId <= 0)
		{
			return [];
		}

		$ids = [];
		$rows = CallAITaskTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $callId)
			->where('TYPE', $senseType->value)
			->where('ID', '<=', $boundaryId)
			->setOrder(['ID' => 'ASC'])
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * Whether the call still has a task nobody has answered for.
	 *
	 * Asked by task and not by type: a type whose newest task is finished may still have an older
	 * one running, and what the caller needs to know is whether the call itself is quiet
	 * {@see \Bitrix\Call\Integration\AI\CallAIService::hasPendingTasks()}.
	 *
	 * Both statuses of an unanswered task are counted, the same two
	 * {@see \Bitrix\Call\Integration\AI\CallAIService::hasAnythingToWaitFor()} counts. A task is
	 * written with {@see self::STATUS_READY} - the default of the field - and turns
	 * {@see self::STATUS_PENDING} only in the callback of a launch that reached the queue
	 * {@see \Bitrix\Call\Integration\AI\CallAIService::launchTask()}. Between the two the row of
	 * the task is already there and its answer is still coming, so a question asked in that window
	 * by another process of the same attempt must not read the call as quiet: it would report the
	 * analysis in the middle of its own chain, out of two generations at once, and again at the
	 * end of it.
	 *
	 * $attemptBoundary is the task whose result the caller is holding, and it bounds the question
	 * to the attempt that result belongs to - for the one type where an unanswered row can outlive
	 * its attempt for good. A transcription older than that task is not a step the attempt is
	 * waiting for: a chain is rooted in a transcription, and a chain that is running has had its
	 * answer already. What it is, is a row of an earlier run that nothing is going to answer for -
	 * the launch a handler of `call:onCallAiTask` refused before the queue, which by the decision
	 * of the owner keeps the status it was created with
	 * {@see \Bitrix\Call\Integration\AI\CallAIService::processTrack()}, or a queued one whose
	 * callback was lost. Left in the question, such a row makes every later analysis of the call
	 * silent for good, and only the agent of the old tasks would free it, sixty days on
	 * {@see \Bitrix\Call\Integration\AI\CallAIService::finishTasks()}.
	 *
	 * Nothing else is bounded by it. A text type cannot be left unanswerable any more - every
	 * place that launches one writes off what the queue did not take
	 * {@see \Bitrix\Call\Integration\AI\CallAIService::writeOffUnlaunchedTask()} - and an older
	 * text row of the very same attempt is the ordinary case of two steps running side by side,
	 * which is exactly what this question exists to catch. Zero asks about the call whole, as
	 * every caller that holds no result of its own means.
	 */
	public static function hasPendingTaskForCall(int $callId, int $attemptBoundary = 0): bool
	{
		$query = CallAITaskTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $callId)
			->whereIn('STATUS', [self::STATUS_READY, self::STATUS_PENDING])
			->setLimit(1)
		;
		if ($attemptBoundary > 0)
		{
			$query->where(
				\Bitrix\Main\ORM\Query\Query::filter()
					->logic('or')
					->where('ID', '>=', $attemptBoundary)
					->where('TYPE', '!=', SenseType::TRANSCRIBE->value)
			);
		}

		return $query->exec()->getSelectedRowsCount() > 0;
	}

	public function getContextId(): string
	{
		return (new \ReflectionClass(static::class))->getShortName();
	}

	/**
	 * Proxies method call to EO_CallAITask type object.
	 * @see EO_CallAITask
	 *
	 * @param string $name
	 * @param array $arguments
	 * @return mixed
	 * @throws NotImplementedException
	 */
	public function __call(string $name, $arguments)
	{
		if (
			$this->task instanceof EO_CallAITask
			&& (
				str_starts_with($name, 'get')
				|| str_starts_with($name, 'set')
				|| str_starts_with($name, 'fill')
				|| in_array($name, ['save', 'delete'])
			)
		)
		{
			return call_user_func_array([$this->task, $name], $arguments);
		}

		throw new NotImplementedException("Class ".$this->task::class." does not implement method '{$name}'");
	}

	/**
	 * Provides payload for AI task.
	 * @param mixed $payload
	 * @return self
	 */
	abstract public function setPayload($payload): self;

	/**
	 * Returns IPayload object for AI service.
	 * @return Result<\Bitrix\AI\Payload\IPayload: payload>
	 */
	abstract public function getAIPayload(): Result;

	public function getAIEngine(\Bitrix\AI\Context $context): ?\Bitrix\AI\Engine
	{
		$engine = \Bitrix\AI\Engine::getByCode($this->getAIEngineCode(), $context, $this->getAIEngineCategory());
		if (!$engine instanceof \Bitrix\AI\Engine)
		{
			$engine = \Bitrix\AI\Engine::getByCategory($this->getAIEngineCategory(), $context);
		}

		return $engine;
	}

	public function getAIEngineContext(): \Bitrix\AI\Context
	{
		$context = new \Bitrix\AI\Context('call', $this->getContextId());

		$context->setParameters([
			'taskId' => $this->task->getId(),
		]);

		if ($this->task->getLanguageId())
		{
			$context->setLanguage($this->task->getLanguageId());
		}

		return $context;
	}

	abstract public function getAIEngineCategory(): string;

	abstract public function getAISenseType(): string;

	/**
	 * Outcome version for compatibility with previous variant.
	 * @return int
	 */
	abstract public function getVersion(): int;

	/**
	 * Allows outputting the chat error message then a task failed.
	 * @return bool
	 */
	abstract public function allowNotifyTaskFailed(): bool;

	/**
	 * Check pending state of the task.
	 * @return bool
	 */
	public function isPending(): bool
	{
		return $this->getStatus() == self::STATUS_PENDING;
	}

	/**
	 * Check pending state of the task.
	 * @return bool
	 */
	public function isFinished(): bool
	{
		return $this->getStatus() == self::STATUS_FINISHED;
	}

	/**
	 * Whether the task ended in a failure - the only state a restart of the failed types selects
	 * by {@see \Bitrix\Call\Integration\AI\CallAIService::restartCallAiTask()}. The code of the
	 * failure narrows nothing: the repeat is asked for by an operator, and that decision outweighs
	 * any guess about the nature of the failure.
	 */
	public function isFailed(): bool
	{
		return $this->getStatus() == self::STATUS_FAILED;
	}

	public function getAIEngineCode(): string
	{
		return '';
	}

	public function getCost(): int
	{
		return 1;
	}

	public function filterResult(array $jsonData): array
	{
		return $jsonData;
	}

	/**
	 * @param \Bitrix\AI\Result $aiResult
	 * @return Outcome
	 */
	public function buildOutcome(\Bitrix\AI\Result $aiResult): Outcome
	{
		$outcome = new Outcome();
		$outcome
			->setType($this->getAISenseType())
			->setCallId($this->getCallId())
			->setProperty('version', $this->getVersion())
		;
		if ($this->getTrackId())
		{
			$outcome->setTrackId($this->getTrackId());
		}

		$jsonData = $this->extractJsonData($aiResult);
		if (is_array($jsonData))
		{
			$jsonData = $this->filterResult($jsonData);
			$outcome->fillFromJson($jsonData);
		}
		else
		{
			$outcome->setContent($aiResult->getPrettifiedData());
		}

		if ($this->getLanguageId())
		{
			$outcome->setLanguageId($this->getLanguageId());
		}

		return $outcome;
	}

	/**
	 * @param \Bitrix\AI\Result $aiResult
	 * @return array|null
	 */
	public function extractJsonData(\Bitrix\AI\Result $aiResult): ?array
	{
		if (is_array($aiResult->getJsonData()))
		{
			return $aiResult->getJsonData();
		}

		$jsonData = null;
		$text = (string)$aiResult->getPrettifiedData();
		try
		{
			if (preg_match('/```json\s*(\{(.|\s)*?\})\s*```/', $text, $matches))
			{
				$jsonData = Json::decode($matches[1]);
			}
			else
			{
				$jsonData = Json::decode($text);
			}
		}
		catch (ArgumentException $exception)
		{
		}

		// A scalar answer such as "42" is valid JSON but carries no fields to fill the outcome from
		return is_array($jsonData) ? $jsonData : null;
	}

	public function decodePayload(string $str): string
	{
		return preg_replace_callback(
			'/\\\\u([0-9a-fA-F]{4})/',
			function ($match)
			{
				return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
			},
			$str
		);
	}

	public function drop(): Result
	{
		$result = $this->task->delete();
		unset($this->task);

		return $result;
	}

	/**
	 * @param int $callId
	 * @return \stdClass|null
	 */
	protected function getMeetingEvent(int $callId): ?\stdClass
	{
		$call = \Bitrix\Call\Call\Registry::getCallWithId($callId);

		static $meetings = [];
		if (
			!isset($meetings[$callId])
			&& ($chatId = $call?->getChatId())
			&& ($chat = Chat::getInstance($chatId))
			&& $chat->getEntityType() == Chat\ExtendedType::Calendar->value
			&& Loader::includeModule('calendar')
			&& ($entryId = $chat->getEntityId())
		)
		{
			/**
			 * @var \Bitrix\Calendar\Core\Mappers\Factory $calendarFactory
			 * @var \Bitrix\Calendar\Core\Event\Event $event
			 */
			$calendarFactory = ServiceLocator::getInstance()->get('calendar.service.mappers.factory');
			$event = $calendarFactory->getEvent()->getById($entryId);
			if ($event)
			{
				$meeting = new \stdClass();
				$meeting->title = $event->getName() ?? '';
				$meeting->description = $event->getDescription() ?? '';

				$eventStart = clone $event->getStart();
				if ($event->getStartTimeZone())
				{
					$eventStart->setTimezone(clone $event->getStartTimeZone()->getTimeZone());
				}
				$meeting->eventStart = $eventStart->getTimestamp();

				$eventEnd = clone $event->getEnd();
				if ($event->getEndTimeZone())
				{
					$eventEnd->setTimezone(clone $event->getEndTimeZone()->getTimeZone());
				}
				$meeting->eventEnd = $eventEnd->getTimestamp();

				$meeting->duration = $meeting->eventEnd - $meeting->eventStart;
				$meeting->overhead = false;

				if ($meeting->duration > 0 && $meeting->duration <= 86400)
				{
					$meeting->callStart = $call->getStartDate()->getTimestamp();
					$meeting->callEnd = $call->getEndDate()?->getTimestamp() ?? (new DateTime())->getTimestamp();
					$meeting->callDuration = $meeting->callEnd - $meeting->callStart;
					if ($meeting->callDuration > 0 && $meeting->callDuration <= 86400)
					{
						$meeting->overhead = $meeting->callDuration > $meeting->duration + 120; // 2 minutes lag
					}
				}

				$meetings[$callId] = $meeting;
			}
		}

		return $meetings[$callId] ?? null;
	}

	/**
	 * Detects user language.
	 * @return string|null
	 */
	protected function getDefaultLanguageId(): string
	{
		return Loader::includeModule('ai') ? \Bitrix\AI\Facade\User::getUserLanguage() : '';
	}
}