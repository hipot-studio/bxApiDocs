<?php

namespace Bitrix\BIConnector\Internal\Repository;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardChat;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardChatTable;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardChatMapper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\SystemException;

class SupersetDashboardChatRepository implements RepositoryInterface
{
	public function __construct(private readonly SupersetDashboardChatMapper $mapper)
	{
	}

	/**
	 * @param mixed $id
	 * @return SupersetDashboardChat|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(mixed $id): ?EntityInterface
	{
		$ormModel = SupersetDashboardChatTable::getById($id)->fetchObject();

		return $ormModel ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	/**
	 * @param int $dashboardId
	 * @return SupersetDashboardChat|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByDashboardId(int $dashboardId): ?EntityInterface
	{
		$ormModel = SupersetDashboardChatTable::getList([
			'filter' => ['=DASHBOARD_ID' => $dashboardId],
		])->fetchObject();

		return $ormModel ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	/**
	 * @param int $chatId
	 * @return SupersetDashboardChat|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByChatId(int $chatId): ?EntityInterface
	{
		$ormModel = SupersetDashboardChatTable::getList([
			'filter' => ['=CHAT_ID' => $chatId],
		])->fetchObject();

		return $ormModel ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	/**
	 * @return SupersetDashboardChat[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getAll(): array
	{
		$ormCollection = SupersetDashboardChatTable::getList([
			'order' => ['ID' => 'ASC'],
		])->fetchCollection();

		$entities = [];
		foreach ($ormCollection as $ormModel)
		{
			$entities[] = $this->mapper->convertFromOrm($ormModel);
		}

		return $entities;
	}

	/**
	 * @param int[] $dashboardIds
	 *
	 * @return SupersetDashboardChat[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByDashboardIds(array $dashboardIds): array
	{
		$dashboardIds = array_values(array_unique(array_filter(array_map('intval', $dashboardIds))));
		if (empty($dashboardIds))
		{
			return [];
		}

		$ormCollection = SupersetDashboardChatTable::getList([
			'filter' => ['@DASHBOARD_ID' => $dashboardIds],
		])->fetchCollection();

		$entities = [];
		foreach ($ormCollection as $ormModel)
		{
			$entities[] = $this->mapper->convertFromOrm($ormModel);
		}

		return $entities;
	}

	/**
	 * @param EntityInterface $entity
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		if (!$entity instanceof SupersetDashboardChat)
		{
			throw new PersistenceException('Entity must be an instance of SupersetDashboardChat');
		}

		try
		{
			$this->assertEntityIsImmutable($entity);
			$result = $this->mapper->convertToOrm($entity)->save();
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if ($result->isSuccess() && !$entity->getId())
		{
			$entity->setId($result->getId());
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to save dashboard chat', errors: $result->getErrorMessages()
			);
		}
	}

	/**
	 * Persisted dashboard-chat link is immutable and may only be re-saved as-is.
	 *
	 * @throws PersistenceException
	 */
	private function assertEntityIsImmutable(SupersetDashboardChat $entity): void
	{
		if (!$entity->getId())
		{
			return;
		}

		$currentEntity = $this->getById($entity->getId());
		if (!$currentEntity instanceof SupersetDashboardChat)
		{
			throw new PersistenceException('Dashboard chat not found.');
		}

		$isModified = (
			$currentEntity->getDashboardId() !== $entity->getDashboardId()
			|| $currentEntity->getChatId() !== $entity->getChatId()
			|| $currentEntity->getCreatedById() !== $entity->getCreatedById()
			|| $currentEntity->getDateCreate()->format('Y-m-d H:i:s') !== $entity->getDateCreate()->format('Y-m-d H:i:s')
		);

		if ($isModified)
		{
			throw new PersistenceException('Dashboard chat link is immutable and cannot be changed after creation.');
		}
	}

	/**
	 * @param mixed $id
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
		try
		{
			$result = SupersetDashboardChatTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to delete dashboard chat', errors: $result->getErrorMessages()
			);
		}
	}
}
