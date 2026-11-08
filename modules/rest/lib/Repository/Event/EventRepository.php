<?php

namespace Bitrix\Rest\Repository\Event;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\EventTable;
use Bitrix\Rest\Internal\Entity\Event;
use Bitrix\Rest\Repository\Exception\EventAlreadyBoundException;
use Bitrix\Rest\Repository\Mapper\EventMapper;

final class EventRepository implements RepositoryInterface
{
	public function __construct(private readonly EventMapper $mapper)
	{
	}

	public function getById(mixed $id): ?EntityInterface
	{
		$entity = EventTable::getById($id)->fetchObject();
		if ($entity !== null)
		{
			return $this->mapper->convertFromOrm($entity);
		}

		return null;
	}

	public function save(EntityInterface $entity): void
	{
		if (!$entity instanceof Event)
		{
			throw new \InvalidArgumentException('Entity must be instance of ' . Event::class);
		}

		$fields = $this->mapper->convertToFields($entity);
		unset($fields['ID']);

		if ($entity->getId() === null)
		{
			$result = EventTable::add($fields);
			if ($result->isSuccess())
			{
				$entity->setId($result->getId());
			}
		}
		else
		{
			$result = EventTable::update($entity->getId(), $fields);
		}

		if ($result->isSuccess())
		{
			return;
		}

		if (in_array('Handler already binded', $result->getErrorMessages(), true))
		{
			throw new EventAlreadyBoundException('Handler already binded');
		}

		throw new PersistenceException(
			'Unable to save event: ' . implode('. ', $result->getErrorMessages())
		);
	}

	public function delete(mixed $id): void
	{
		EventTable::delete($id);
	}

	/**
	 * @return Event[]
	 */
	public function getAllByUniqueParams(
		string $name,
		?string $handler = null,
		?int $appId = null,
		?int $userId = null,
		?string $connectorId = null,
		?int $version = 1
	): array
	{
		$filter = [
			'=EVENT_NAME' => $name
		];

		if ($handler !== null)
		{
			$filter['=EVENT_HANDLER'] = $handler;
		}
		if ($appId !== null)
		{
			$filter['=APP_ID'] = $appId;
		}
		if ($userId !== null)
		{
			$filter['=USER_ID'] = $userId;
		}
		if ($connectorId !== null)
		{
			$filter['=CONNECTOR_ID'] = $connectorId;
		}
		if ($version !== null)
		{
			$filter['=VERSION'] = $version;
		}

		$events = EventTable::getList([
			'filter' => $filter,
		]);

		$result = [];

		while ($event = $events->fetchObject())
		{
			$result[] = $this->mapper->convertFromOrm($event);
		}

		return $result;
	}

	public function getQueryByUniqueParams(
		string $name,
		?string $handler = null,
		?int $appId = null,
		?int $userId = null,
		?string $connectorId = null,
		int $version = 1
	): Query
	{
		$query = EventTable::query()
			->setSelect([
				'ID',
				'APP_ID',
				'EVENT_NAME',
				'EVENT_HANDLER',
				'USER_ID',
				'APPLICATION_TOKEN',
				'CONNECTOR_ID',
				'JSON_OPTIONS',
				'APP_CODE' => 'REST_APP.CLIENT_ID',
				'APP_ACTIVE' => 'REST_APP.ACTIVE',
				'APP_INSTALLED' => 'REST_APP.INSTALLED',
			])
			->where('EVENT_NAME', $name)
			->where('VERSION', $version)
			->where(
				(Query::filter()
					->logic('or')
					->where(
						Query::filter()
							->where('REST_APP.ACTIVE', '=', AppTable::ACTIVE)
							->where('REST_APP.INSTALLED', '=', AppTable::INSTALLED)
					)
					->whereNull('REST_APP.CLIENT_ID')
				)
			)
		;

		if ($handler !== null)
		{
			$query->where('EVENT_HANDLER', $handler);
		}
		if ($appId !== null)
		{
			$query->where('APP_ID', $appId);
		}
		if ($userId !== null)
		{
			$query->where('USER_ID', $userId);
		}
		if ($connectorId !== null)
		{
			$query->where('CONNECTOR_ID', $connectorId);
		}

		return $query;
	}
}