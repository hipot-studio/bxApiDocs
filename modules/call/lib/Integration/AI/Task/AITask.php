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
 * @method \Bitrix\Im\Model\EO_Call fillCall()
 * @method \Bitrix\Call\Track fillTrack()
 * @method \Bitrix\Call\Integration\AI\Outcome fillOutcome()
 * @method \Bitrix\Main\ORM\Data\Result save()
 * @method \Bitrix\Main\ORM\Data\Result delete()
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
		try
		{
			if (preg_match('/```json\s*(\{(.|\s)*?\})\s*```/', $aiResult->getPrettifiedData(), $matches))
			{
				$jsonData = Json::decode($matches[1]);
			}
			else
			{
				$jsonData = Json::decode($aiResult->getPrettifiedData());
			}
		}
		catch (ArgumentException $exception)
		{
		}

		return $jsonData;
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
		$call = \Bitrix\Im\Call\Registry::getCallWithId($callId);

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