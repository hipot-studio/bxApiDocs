<?php

use Bitrix\Bizproc\Activity\Enum\SchedulerTransport;
use Bitrix\Bizproc\SchedulerEventTable;
use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Entity\WorkflowStartMessage;
use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Entity\WorkflowResumeMessage;
use Bitrix\Main\Loader;

class CBPSchedulerService extends CBPRuntimeService
{
	public const DEFAULT_SORT = 100;
	public const REPEAT_SORT = 75;

	/**
	 * @param bool $withType Return as array [value, type].
	 * @return int|array
	 * @throws \Bitrix\Main\ArgumentNullException
	 */
	public static function getDelayMinLimit($withType = false)
	{
		$result = (int) \Bitrix\Main\Config\Option::get('bizproc', 'delay_min_limit', 0);
		if (!$withType)
			return $result;
		$type = 's';
		if ($result > 0)
		{
			if ($result % (3600 * 24) == 0)
			{
				$result = $result / (3600 * 24);
				$type = 'd';
			}
			elseif ($result % 3600 == 0)
			{
				$result = $result / 3600;
				$type = 'h';
			}
			elseif ($result % 60 == 0)
			{
				$result = $result / 60;
				$type = 'm';
			}
		}
		return array($result, $type);
	}

	public static function setDelayMinLimit($limit, $type = 's')
	{
		$limit = (int)$limit;
		switch ($type)
		{
			case 'd':
				$limit *= 3600 * 24;
				break;
			case 'h':
				$limit *= 3600;
				break;
			case 'm':
				$limit *= 60;
				break;
			default:
				break;
		}
		\Bitrix\Main\Config\Option::set('bizproc', 'delay_min_limit', $limit);
	}

	public static function getDelayMaxDays(): int
	{
		return (int)\Bitrix\Main\Config\Option::get('bizproc', 'delay_max_days', 0);
	}

	public static function setDelayMaxDays(int $days): void
	{
		\Bitrix\Main\Config\Option::set('bizproc', 'delay_max_days', $days);
	}

	public function subscribeOnTime($workflowId, $eventName, $expiresAt, int $sort = self::DEFAULT_SORT)
	{
		$workflowId = preg_replace('#[^a-z0-9.]#i', '', $workflowId);
		$eventName = preg_replace('#[^a-z0-9._-]#i', '', $eventName);

		return self::addAgent($workflowId, $eventName, static::calculateExpirationTime($expiresAt), sort: $sort);
	}

	public static function calculateExpirationTime(int $timestamp): int
	{
		$now = time();

		['min' => $min, 'max' => $max] = static::getDelayBounds();

		$minTimestamp = $min > 0 ? $now + $min : null;
		$maxTimestamp = $max > 0 ? $now + $max : null;

		return static::clamp($timestamp, $minTimestamp, $maxTimestamp);
	}

	public static function calculateDelay(int $delay): int
	{
		['min' => $min, 'max' => $max] = static::getDelayBounds();
		$max = $max ?: null;

		return static::clamp($delay, $min, $max);
	}

	private static function addAgent(
		$workflowId,
		$eventName,
		$expiresAt,
		int $counter = 0,
		int $sort = self::DEFAULT_SORT,
	)
	{
		$params = "['SchedulerService' => 'OnAgent', 'Counter' => {$counter}, 'Sort' => {$sort}]";
		$name = "CBPSchedulerService::OnAgent('{$workflowId}', '{$eventName}', {$params});";

		return self::addAgentInternal($name, $expiresAt, $sort);
	}

	public function unSubscribeOnTime($id)
	{
		CAgent::Delete($id);
	}

	public static function onAgent($workflowId, $eventName, $eventParameters = array())
	{
		try
		{
			CBPRuntime::SendExternalEvent($workflowId, $eventName, $eventParameters);
		}
		catch (Exception $e)
		{
			if ($e->getCode() === \CBPRuntime::EXCEPTION_CODE_INSTANCE_LOCKED)
			{
				$counter = isset($eventParameters['Counter']) ? (int) $eventParameters['Counter'] : 0;
				$expiresAt = self::getExpiresTimeByCounter($counter);
				if ($expiresAt)
				{
					++$counter;
					self::addAgent($workflowId, $eventName, $expiresAt, $counter, self::REPEAT_SORT);
				}
			}
			elseif (
				$e->getCode() !== \CBPRuntime::EXCEPTION_CODE_INSTANCE_NOT_FOUND
				&& $e->getCode() !== \CBPRuntime::EXCEPTION_CODE_INSTANCE_TARIFF_LIMIT_EXCEED
			)
			{
				self::logUnknownException($e);
			}
		}
	}

	public function subscribeOnEvent(
		$workflowId,
		$eventHandlerName,
		$eventModule,
		$eventName,
		$entityId = null,
		int $sort = self::DEFAULT_SORT,
		?SchedulerTransport $schedulerTransport = null,
	): ?int
	{
		$resultId = null;
		$entityKey = null;
		if (is_array($entityId))
		{
			foreach ($entityId as $entityKey => $entityId)
				break;
		}
		elseif ($entityId !== null)
		{
			$entityKey = 0;
		}

		if (is_array($entityId))
		{
			$entityId = current(\CBPHelper::makeArrayFlat($entityId));
		}

		if (!SchedulerEventTable::isSubscribed($workflowId, $eventHandlerName, $eventModule, $eventName, $entityId))
		{
			$result = SchedulerEventTable::add([
				'WORKFLOW_ID' => (string)$workflowId,
				'HANDLER' => (string)$eventHandlerName,
				'EVENT_MODULE' => (string)$eventModule,
				'EVENT_TYPE' => (string)$eventName,
				'ENTITY_ID' => (string)$entityId,
			]);
			$resultId = (int)$result->getId();
		}

		$methodName = 'sendEvents';
		$args = [$eventModule, $eventName, $entityKey];
		if ($schedulerTransport !== null)
		{
			$methodName = 'sendEventsWithTransport';
			$args[] = $schedulerTransport->value;
		}

		RegisterModuleDependences(
			$eventModule,
			$eventName,
			'bizproc',
			'CBPSchedulerService',
			$methodName,
			$sort,
			'',
			$args
		);

		return $resultId;
	}

	public function unSubscribeOnEvent(
		$workflowId,
		$eventHandlerName,
		$eventModule,
		$eventName,
		$entityId = null,
		?SchedulerTransport $schedulerTransport = null,
	)
	{
		// Clean old-style registry entry.
		UnRegisterModuleDependences(
			$eventModule,
			$eventName,
			"bizproc",
			"CBPSchedulerService",
			"OnEvent",
			"",
			array($workflowId, $eventHandlerName, array('SchedulerService' => 'OnEvent', 'EntityId' => $entityId))
		);

		$entityKey = null;
		if (is_array($entityId))
		{
			foreach ($entityId as $entityKey => $entityId)
				break;
		}
		elseif ($entityId !== null)
		{
			$entityKey = 0;
		}

		if (is_array($entityId))
		{
			$entityId = current(\CBPHelper::makeArrayFlat($entityId));
		}

		SchedulerEventTable::deleteBySubscription($workflowId, $eventHandlerName, $eventModule, $eventName, $entityId);

		if (!SchedulerEventTable::hasSubscriptions($eventModule, $eventName))
		{
			$methodName = 'sendEvents';
			$args = [$eventModule, $eventName, $entityKey];
			if ($schedulerTransport !== null)
			{
				$methodName = 'sendEventsWithTransport';
				$args[] = $schedulerTransport->value;
			}

			UnRegisterModuleDependences(
				$eventModule,
				$eventName,
				'bizproc',
				'CBPSchedulerService',
				$methodName,
				'',
				$args
			);
		}
	}

	public function unSubscribeByEventId(int $eventId, $entityKey = null, ?SchedulerTransport $schedulerTransport = null)
	{
		$event = SchedulerEventTable::getList([
			'select' => ['WORKFLOW_ID', 'HANDLER','EVENT_MODULE', 'EVENT_TYPE', 'ENTITY_ID'],
			'filter' => ['=ID' => $eventId],
		])->fetch();

		if ($event)
		{
			$this->unSubscribeOnEvent(
				$event['WORKFLOW_ID'],
				$event['HANDLER'],
				$event['EVENT_MODULE'],
				$event['EVENT_TYPE'],
				$entityKey ? [$entityKey => $event['ENTITY_ID']] : $event['ENTITY_ID'],
				schedulerTransport: $schedulerTransport,
			);
		}
	}

	public function subscribeStartWorkflow(string $workflowId, int $delay = 0): void
	{
		$message = new WorkflowStartMessage($workflowId);
		$params = [];
		if ($delay > 0)
		{
			$params[] = new Bitrix\Main\Messenger\Entity\ProcessingParam\DelayParam($delay);
		}
		$message->send('start_workflow_queue', $params);
	}

	/**
	 * @deprecated
	 * @param $workflowId
	 * @param $eventName
	 * @param array $arEventParameters
	 */
	public static function onEvent($workflowId, $eventName, $arEventParameters = array())
	{
		$num = func_num_args();
		if ($num > 3)
		{
			for ($i = 3; $i < $num; $i++)
				$arEventParameters[] = func_get_arg($i);
		}

		if (is_array($arEventParameters["EntityId"]))
		{
			foreach ($arEventParameters["EntityId"] as $key => $value)
			{
				if (!isset($arEventParameters[0][$key]) || $arEventParameters[0][$key] != $value)
					return;
			}
		}
		elseif ($arEventParameters["EntityId"] != null && $arEventParameters["EntityId"] != $arEventParameters[0])
			return;

		global $BX_MODULE_EVENT_LAST;
		$lastEvent = $BX_MODULE_EVENT_LAST;

		try
		{
			CBPRuntime::SendExternalEvent($workflowId, $eventName, $arEventParameters);
		}
		catch (Exception $e)
		{
			//Clean-up records if instance not found
			if (
				$e->getCode() === \CBPRuntime::EXCEPTION_CODE_INSTANCE_NOT_FOUND
				&& $lastEvent['TO_MODULE_ID'] == 'bizproc'
				&& $lastEvent['TO_CLASS'] == 'CBPSchedulerService'
				&& $lastEvent['TO_METHOD'] == 'OnEvent'
				&& is_array($lastEvent['TO_METHOD_ARG'])
				&& $lastEvent['TO_METHOD_ARG'][0] == $workflowId
			)
			{
				UnRegisterModuleDependences(
					$lastEvent['FROM_MODULE_ID'],
					$lastEvent['MESSAGE_ID'],
					"bizproc",
					"CBPSchedulerService",
					"OnEvent",
					"",
					$lastEvent['TO_METHOD_ARG']
				);
			}
			elseif ($e->getCode() !== \CBPRuntime::EXCEPTION_CODE_INSTANCE_NOT_FOUND)
			{
				self::logUnknownException($e);
			}
		}
	}

	public static function sendEvents($eventModule, $eventName, $entityKey)
	{
		if ($eventModule === 'bizproc' && $eventName === 'OnWorkflowComplete' && $entityKey === null)
		{
			//delete invalid subscription
			UnRegisterModuleDependences(
				$eventModule,
				$eventName,
				'bizproc',
				'CBPSchedulerService',
				'sendEvents',
				'',
				[$eventModule, $eventName, $entityKey]
			);

			return false;
		}

		$args = [];
		$num = func_num_args();
		if ($num > 3)
		{
			$args = array_slice(func_get_args(), 3);
		}

		self::sendEventsInternal($eventModule, $eventName, $entityKey, $args);
	}

	public static function sendEventsWithTransport(
		string $eventModule,
		string $eventName,
		mixed $entityKey,
		string $schedulerTransportType,
	): void
	{
		try
		{
			$schedulerTransport = SchedulerTransport::from($schedulerTransportType);
		}
		catch (\Throwable $exception)
		{
			self::logUnknownException($exception);

			return;
		}

		$args = [];
		$num = func_num_args();
		if ($num > 4)
		{
			$args = array_slice(func_get_args(), 4);
		}

		self::sendEventsInternal($eventModule, $eventName, $entityKey, $args, $schedulerTransport->value);
	}

	private static function sendEventsInternal(
		string $eventModule,
		string $eventName,
		mixed $entityKey,
		array $args,
		?string $schedulerTransport = null,
	): void
	{
		$eventParameters = [
			'SchedulerService' => 'OnEvent',
			'eventModule' => $eventModule,
			'eventName' => $eventName,
		];

		if ($schedulerTransport !== null)
		{
			$eventParameters['schedulerTransport'] = $schedulerTransport;
		}

		$eventParameters += array_values($args);

		$filter = [
			'=EVENT_MODULE' => $eventModule,
			'=EVENT_TYPE' => $eventName,
		];

		$entityId = null;
		if ($entityKey === 0 && isset($eventParameters[0]))
		{
			$entityId = (string)$eventParameters[0];
		}
		elseif ($entityKey !== null && isset($eventParameters[0][$entityKey]))
		{
			$entityId = (string)$eventParameters[0][$entityKey];
		}

		if ($entityId !== null)
		{
			$filter['=ENTITY_ID'] = $entityId;
		}

		$iterator = SchedulerEventTable::getList(['filter' => $filter]);

		while ($event = $iterator->fetch())
		{
			$event['EVENT_PARAMETERS'] = $eventParameters;
			self::sendEventToWorkflow($event);
		}
	}

	public static function repeatEvent($eventId, $counter = 0)
	{
		$iterator = SchedulerEventTable::getById($eventId);
		$event = $iterator->fetch();

		if ($event && Loader::includeModule($event['EVENT_MODULE']))
		{
			self::sendEventToWorkflow($event, $counter);
		}
	}

	/**
	 * @param string $workflowId
	 * @param string $handler
	 * @param int $counter
	 * @return void
	 */
	public static function retrySendEventToWorkflow(string $workflowId, string $handler, int $counter = 0): void
	{
		$event = [
			'WORKFLOW_ID' => $workflowId,
			'HANDLER' => $handler,
			'EVENT_PARAMETERS' => [],
		];
		self::sendEventToWorkflow($event, $counter);
	}

	private static function sendEventToWorkflow($event, $counter = 0)
	{
		try
		{
			CBPRuntime::SendExternalEvent($event['WORKFLOW_ID'], $event['HANDLER'], $event['EVENT_PARAMETERS']);
		}
		catch (Exception $e)
		{
			if (
				$e->getCode() === \CBPRuntime::EXCEPTION_CODE_INSTANCE_NOT_FOUND
				|| $e->getCode() === \CBPRuntime::EXCEPTION_CODE_INSTANCE_TARIFF_LIMIT_EXCEED
			)
			{
				SchedulerEventTable::delete($event['ID']);
			}
			elseif ($e->getCode() === \CBPRuntime::EXCEPTION_CODE_INSTANCE_LOCKED)
			{
				self::addEventRepeatAgent($event, $counter);
			}
			else
			{
				self::logUnknownException($e);
			}
		}
	}

	private static function filterEventParameters(array $parameters)
	{
		$filtered = [];
		foreach ($parameters as $key => $parameter)
		{
			if (is_scalar($parameter))
			{
				$filtered[$key] = $parameter;
			}
			elseif (is_array($parameter))
			{
				$filtered[$key] = self::filterEventParameters($parameter);
			}
		}
		return $filtered;
	}

	private static function addEventRepeatAgent($event, $counter = 0)
	{
		$expiresAt = self::getExpiresTimeByCounter($counter);

		if ($expiresAt)
		{
			if ($counter === 0)
			{
				$filteredParameters = self::filterEventParameters($event['EVENT_PARAMETERS']);
				SchedulerEventTable::update($event['ID'], ['EVENT_PARAMETERS' => $filteredParameters]);
			}

			$schedulerTransport = $event['EVENT_PARAMETERS']['schedulerTransport'] ?? null;
			if ($schedulerTransport === SchedulerTransport::Messenger->value)
			{
				$delay = $expiresAt - time();
				self::enqueueResumeWorkflow($event['WORKFLOW_ID'], $event['HANDLER'], $delay);

				return;
			}

			++$counter;
			$eventId = $event['ID'];
			$name = "CBPSchedulerService::repeatEvent({$eventId}, {$counter});";
			self::addAgentInternal($name, $expiresAt, sort: self::REPEAT_SORT);
		}
	}

	private static function addAgentInternal($name, $expiresAt, $sort = self::DEFAULT_SORT)
	{
		CTimeZone::Disable();
		$result = CAgent::AddAgent(
			$name,
			"bizproc",
			"N",
			10,
			"",
			"Y",
			date($GLOBALS["DB"]->DateFormatToPHP(FORMAT_DATETIME), $expiresAt),
			sort: $sort,
		);
		CTimeZone::Enable();
		return $result;
	}

	private static function getExpiresTimeByCounter($counter = 0)
	{
		if ($counter >= 0 && $counter < 3)
		{
			$minute = 60;
			return time() + [0 => (1 * $minute), 1 => (5 * $minute), 2 => (10 * $minute)][$counter];
		}
		return false;
	}

	private static function logUnknownException(Throwable $exception): void
	{
		\Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}

	public static function enqueueResumeWorkflow(string $workflowId, string $eventName, int $delay = 0): void
	{
		$message = new WorkflowResumeMessage($workflowId, $eventName);
		$params = [];

		$delay = self::calculateDelay($delay);
		if ($delay > 0)
		{
			$params[] = new Bitrix\Main\Messenger\Entity\ProcessingParam\DelayParam($delay);
		}

		$message->send('resume_workflow_queue', $params);
	}

	public function sendResumeWorkflowMessage(string $workflowId, string $eventName, int $delay = 0)
	{
		self::enqueueResumeWorkflow($workflowId, $eventName, $delay);
	}

	private static function clamp(int $value, ?int $min = null, ?int $max = null): int
	{
		return min(
			$max ?? $value,
			max($value, $min ?? $value)
		);
	}


	private static function getDelayBounds(): array
	{
		return [
			'min' => static::getDelayMinLimit(false),
			'max' => static::getDelayMaxDays() * 86400,
		];
	}
}
