<?php

namespace Bitrix\Rest\V3\Event;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Event\Sender;
use Bitrix\Rest\Internal\Entity\Event;
use Bitrix\Rest\Repository\Event\EventRepository;
use Bitrix\Rest\Tools\Diagnostics\Event\LogType;
use Bitrix\Rest\Tools\Diagnostics\LoggerManager;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class Callback
{
	public static function __callStatic($name, $arguments)
	{
		$bitrixEvent = ($arguments[0] ?? null) instanceof \Bitrix\Main\Event ? $arguments[0] : null;
		$isLegacyTransport = $bitrixEvent === null;

		$eventName = $bitrixEvent !== null
			? $bitrixEvent->getEventType()
			: (Sender::parseEventName($name)['EVENT'] ?? '');

		/** @var EventRegistry $eventRegistry */
		$registry = ServiceLocator::getInstance()->get(EventRegistry::class);
		$eventDescriptor = $registry->get($eventName);
		if ($eventDescriptor === null)
		{
			return null;
		}

		if ($isLegacyTransport)
		{
			LoggerManager::getInstance()->getLogger()?->info(
				"\n{delimiter}\n"
				. "{date} - {host}\n{delimiter}\n"
				. "Event {eventName} invoked via legacy ExecuteModuleEventEx transport.\n{delimiter}\n"
				. "{arguments}",
				[
					'responseData' => $arguments,
					'message' => LogType::EVENT_START->value,
					'module' => $eventDescriptor->module,
					'name' => $eventDescriptor->name,
					'arguments' => $arguments,
					'transport' => 'legacy',
				]
			);
		}
		else
		{
			LoggerManager::getInstance()->getLogger()?->info(
				"\n{delimiter}\n"
				. "{date} - {host}\n{delimiter}\n"
				. "Event {eventName} starts. \n{delimiter}\n"
				. "{arguments}",
				[
					'responseData' => $arguments,
					'message' => LogType::EVENT_START->value,
					'module' => $eventDescriptor->module,
					'name' => $eventDescriptor->name,
					'arguments' => $arguments,
					'transport' => 'd7',
				]
			);
		}

		$appHoldExceptId = self::resolveHoldExceptAppId($bitrixEvent, $arguments);

		$eventParameters = self::resolveEventParameters($bitrixEvent, $arguments);
		$eventParametersKeys = array_keys($eventParameters);

		$call = [];

		/** @var EventRepository $eventRepository */
		$eventRepository = ServiceLocator::getInstance()->get(EventRepository::class);

		$eventQuery = $eventRepository->getQueryByUniqueParams(
			name: $eventDescriptor->name,
			appId: $appHoldExceptId,
			version: Event::VERSION_REST_V3,
		);

		$handlerFound = false;

		$eventData = null;

		$eventResult = $eventQuery->exec();

		while ($handlerData = $eventResult->fetch())
		{
			if ($eventData === null)
			{
				$eventData = self::buildEventData(
					$eventDescriptor,
					$arguments,
					$eventParameters,
					self::resolveEventEntityId($bitrixEvent, $arguments),
					$bitrixEvent,
				);
			}

			$handlerFound = true;
			if (!empty($handlerData['APP_CODE']))
			{
				$appStatus = AppTable::getAppStatusInfo($handlerData['APP_CODE'], '');
				if ($appStatus['PAYMENT_EXPIRED'] === 'Y')
				{
					LoggerManager::getInstance()->getLogger()?->info(
						"\n{delimiter}\n"
						. "{date} - {host}\n{delimiter}\n"
						. "Event {eventName} skipped because PAYMENT_EXPIRED: \n"
						. "{appStatus}",
						[
							'responseData' => $arguments,
							'message' => LogType::SKIP_BY_PAYMENT_EXPIRED->value,
							'module' => $eventDescriptor->module,
							'name' => $eventDescriptor->name,
							'arguments' => $arguments,
							'appStatus' => $appStatus,
						]
					);

					continue;
				}
			}

			$jsonOptions = $handlerData['JSON_OPTIONS'] ?? [];
			$filterFields = $jsonOptions['filterFields'] ?? [];
			$selectFields = $jsonOptions['selectFields'] ?? [];
			$updateFields = $jsonOptions['updateFields'] ?? [];

			// Skip subscription if none of its watched update fields were actually changed
			if (!empty($updateFields) && empty(array_intersect($updateFields, array_keys($eventParameters))))
			{
				continue;
			}


			// Skip subscription if entity data does not satisfy all filter conditions
			if (!empty($filterFields))
			{
				$filterPassed = true;
				foreach ($filterFields as $filterField => $filterValue)
				{
					if (
						!array_key_exists($filterField, $eventData)
						|| $eventData[$filterField] != $filterValue
					)
					{
						$filterPassed = false;
						break;
					}
				}

				if (!$filterPassed)
				{
					continue;
				}
			}

			// Build payload: use only selected fields, or full entity data if no selection specified
			$handlerArguments = !empty($selectFields)
				? array_intersect_key($eventData, array_flip($selectFields))
				: $eventData;

			$call[] = [$handlerData, $handlerArguments, $eventDescriptor->additionalData ?: []];
		}

		if (!empty($call))
		{
			Sender::call($call);
		}

		if (!$handlerFound)
		{
			Sender::unbind($eventDescriptor->module, $eventDescriptor->name, Event::VERSION_REST_V3);
		}
	}

	private static function resolveHoldExceptAppId(?\Bitrix\Main\Event $event, array $arguments): ?int
	{
		$clientId = null;

		if ($event !== null)
		{
			$parameters = $event->getParameters();
			if (!empty($parameters['REST_EVENT_HOLD_EXCEPT_APP']))
			{
				$clientId = $parameters['REST_EVENT_HOLD_EXCEPT_APP'];
			}
		}

		if ($clientId === null && !empty($arguments[1]['REST_EVENT_HOLD_EXCEPT_APP']))
		{
			$clientId = $arguments[1]['REST_EVENT_HOLD_EXCEPT_APP'];
		}

		if ($clientId === null || $clientId === '')
		{
			return null;
		}

		$app = AppTable::getByClientId($clientId);
		if (isset($app['ID']) && (int)$app['ID'] > 0)
		{
			return (int)$app['ID'];
		}

		return null;
	}

	private static function resolveEventParameters(?\Bitrix\Main\Event $event, array $arguments): array
	{
		if ($event !== null)
		{
			return $event->getParameters();
		}

		return is_array($arguments[0] ?? null) ? $arguments[0] : [];
	}

	private static function resolveEventEntityId(?\Bitrix\Main\Event $event, array $arguments): mixed
	{
		if ($event !== null)
		{
			return $event->getParameter('ID');
		}

		$parameters = self::resolveEventParameters(null, $arguments);

		return $parameters['ID'] ?? null;
	}

	private static function buildEventData(
		EventDescriptor $eventDescriptor,
		array $arguments,
		array $eventParameters,
		mixed $eventEntityId,
		?\Bitrix\Main\Event $bitrixEvent = null,
	): array
	{
		$eventHandlerClass = $eventDescriptor->payload->getHandlerClass();
		$eventHandler = $eventHandlerClass ? ServiceLocator::getInstance()->get($eventHandlerClass) : null;

		if ($eventDescriptor->payload->getType() === EventType::Dto)
		{
			$payload = $eventParameters;

			/** @var Dto $originalDto */
			$originalDto = $eventDescriptor->payload->dtoClass::create();

			$ormEntityAttribute = $originalDto->getAttributeByName(OrmEntity::class);
			if ($ormEntityAttribute !== null && $eventEntityId !== null)
			{
				$entityOrmData = $ormEntityAttribute->entity::query()
					->setSelect(['*'])
					->where('ID', $eventEntityId)
					->fetch();

				if (is_array($entityOrmData))
				{
					$payload = array_merge($payload, $entityOrmData);
				}
				else
				{
					LoggerManager::getInstance()->getLogger()?->info(
						"\n{delimiter}\n"
						. "{date} - {host}\n{delimiter}\n"
						. "Event {eventName}: ORM entity ID={entityId} not found, using event parameters only.\n{delimiter}\n",
						[
							'message' => LogType::EVENT_START->value,
							'module' => $eventDescriptor->module,
							'name' => $eventDescriptor->name,
							'entityId' => $eventEntityId,
						]
					);
				}
			}

			/** @var Mapper $mapper */
			$mapper = ServiceLocator::getInstance()->get($eventDescriptor->payload->mapperClass);
			$dto = $mapper->mapOne($payload);

			return $eventHandler !== null ? $eventHandler->handle($dto->toArray()) : $dto->toArray();
		}

		if ($eventHandler !== null)
		{
			$handlerInput = $bitrixEvent !== null ? [$eventParameters] : $arguments;

			return $eventHandler->handle($handlerInput);
		}

		return $bitrixEvent !== null ? $eventParameters : $arguments;
	}
}
