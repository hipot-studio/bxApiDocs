<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Event;

use Bitrix\Main\Composite\Internals\Locker;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\SystemException;
use Bitrix\Rest\Event\Sender;
use Bitrix\Rest\HandlerHelper;
use Bitrix\Rest\Internal\Entity\Event;
use Bitrix\Rest\Repository\Event\EventRepository;
use Bitrix\Rest\Repository\Exception\EventAlreadyBoundException;
use Bitrix\Rest\RestException;
use Bitrix\Rest\V3\Event\EventRegistry;
use Bitrix\Rest\V3\Exception\InsufficientScopeException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Realisation\Dto\EventDto;
use Bitrix\Rest\V3\Realisation\Exception\Event\AlreadyBoundException;
use Bitrix\Rest\V3\Realisation\Exception\Event\InvalidHandlerException;
use Bitrix\Rest\V3\Realisation\Exception\Event\NotFoundException;

final class OnlineEventService
{
	public function __construct(
		private readonly EventRepository $repository,
		private readonly ?EventRegistry $eventRegistry = null,
		private readonly ?OnlineEventJsonOptionsBuilder $jsonOptionsBuilder = null,
	)
	{
	}

	/**
	 * @param array $clientInfo App row from AppTable::getByClientId()
	 * @param callable(array): bool $scopeChecker
	 */
	public function bind(
		EventDto $dto,
		AddRequest $request,
		int $appId,
		?int $userId,
		string $connectorId,
		array $clientInfo,
		callable $scopeChecker,
	): void
	{
		$registry = $this->eventRegistry ?? ServiceLocator::getInstance()->get(EventRegistry::class);
		$eventDescriptor = $registry->get($dto->name);

		if (!$eventDescriptor)
		{
			throw new NotFoundException($dto->name);
		}

		if (!$scopeChecker($eventDescriptor->scopes))
		{
			throw new InsufficientScopeException();
		}

		try
		{
			HandlerHelper::checkCallback($dto->handler, $clientInfo);
		}
		catch (RestException $e)
		{
			throw new InvalidHandlerException($e);
		}

		$eventName = $eventDescriptor->name;

		$existEvents = $this->repository->getAllByUniqueParams(
			name: $eventName,
			handler: $dto->handler,
			appId: $appId,
			userId: $userId,
			connectorId: $connectorId,
			version: Event::VERSION_REST_V3,
		);

		if (count($existEvents) > 0)
		{
			throw new AlreadyBoundException();
		}

		$jsonOptionsBuilder = $this->jsonOptionsBuilder ?? new OnlineEventJsonOptionsBuilder();
		$jsonOptions = $jsonOptionsBuilder->build($dto, $eventDescriptor, $request);

		$event = new Event(
			id: null,
			appId: $appId,
			name: $eventName,
			handler: $dto->handler,
			userId: $userId,
			title: null,
			comment: null,
			applicationToken: null,
			connectorId: $connectorId,
			integrationId: null,
			options: [],
			version: Event::VERSION_REST_V3,
			jsonOptions: $jsonOptions,
		);

		$lockKey = implode('|', [
			$event->getAppId(),
			$event->getName(),
			$event->getHandler(),
			$event->getConnectorId(),
			$event->getUserId(),
		]);

		if (!Locker::lock($lockKey))
		{
			throw new SystemException('Process of binding the handler has already started.');
		}

		try
		{
			try
			{
				$this->repository->save($event);
			}
			catch (EventAlreadyBoundException)
			{
				throw new AlreadyBoundException();
			}

			Sender::bind($eventDescriptor->module, $eventDescriptor->name, Event::VERSION_REST_V3);
		}
		finally
		{
			Locker::unlock($lockKey);
		}
	}

	public function unbind(
		EventDto $dto,
		?int $appId,
		?int $userId,
		string $connectorId,
	): void
	{
		$registry = $this->eventRegistry ?? ServiceLocator::getInstance()->get(EventRegistry::class);
		$eventDescriptor = $registry->get($dto->name);
		if (!$eventDescriptor)
		{
			throw new NotFoundException($dto->name);
		}

		$handler = $dto->getFields()['handler']->isInitialized() ? $dto->handler : null;
		$eventName = $eventDescriptor->name;

		$existEvents = $this->repository->getAllByUniqueParams(
			name: $eventName,
			handler: $handler,
			appId: $appId,
			userId: $userId,
			connectorId: $connectorId,
			version: Event::VERSION_REST_V3,
		);

		foreach ($existEvents as $existEvent)
		{
			$this->repository->delete($existEvent->getId());
		}

		if ($this->repository->getAllByUniqueParams(name: $eventName, version: Event::VERSION_REST_V3) === [])
		{
			Sender::unbind($eventDescriptor->module, $eventDescriptor->name, Event::VERSION_REST_V3);
		}
	}
}
