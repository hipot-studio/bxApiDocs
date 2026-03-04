<?php

declare(strict_types=1);

namespace Bitrix\Main\Messenger\Internals\Storage\Db;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Messenger\Entity\MessageBox;
use Bitrix\Main\Messenger\Entity\MessageBoxCollection;
use Bitrix\Main\Messenger\Internals\Exception\Storage\MappingException;
use Bitrix\Main\Messenger\Internals\Exception\Storage\PersistenceException;
use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessengerMessageTable;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\Objectify\Collection;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessageStatus;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class MessageRepository
{
	private MessageMapper $mapper;

	public function __construct(private readonly Entity $tableEntity)
	{
		$this->mapper = new MessageMapper($tableEntity);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getOneByQueue(string $queueId): ?MessageBox
	{
		$query = $this->buildReadyMessageQuery($queueId);

		$query->setLimit(1);

		$item = $query->fetchObject();

		if (!$item)
		{
			return null;
		}

		return $this->getEntityFromOrmItem($item);
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 * @throws MappingException
	 * @throws SystemException
	 */
	public function getReadyMessagesOfQueue(
		string $queueId,
		int $limit = 50,
		int $processingLimit = 100,
	): MessageBoxCollection
	{
		$query = $this->buildReadyMessageQuery($queueId);

		$limit = $limit > 0 ? min($limit, 1000) : 50;

		if ($limit > $processingLimit)
		{
			// @todo This is a temporary solution to avoid fetching more messages than the receiver can process.
			//  The better solution is to implement synchronization primitives.
			//  After implementing synchronization primitives, this check can be removed and the receiver will be able
			//  to fetch as many messages as it can process without worrying about other receivers.
			throw new ArgumentOutOfRangeException(
				sprintf(
					'The requested limit (%d) is greater than the processing limit (%d)',
					$limit,
					$processingLimit,
				),
			);
		}

		$query->setLimit($processingLimit);

		/** @var Collection $items */
		$items = $query->fetchCollection();

		$collection = new MessageBoxCollection();

		if ($items->isEmpty())
		{
			return $collection;
		}

		$items = $items->filter(
			fn($item) => $item->getStatus() === MessageStatus::New->value,
		);

		if ($items->isEmpty())
		{
			return $collection;
		}

		foreach ($items as $ormItem)
		{
			if ($messageBox = $this->getEntityFromOrmItem($ormItem))
			{
				$collection->add($messageBox);

				if (--$limit < 1)
				{
					break;
				}
			}
			else
			{
				$ormItem->delete();
			}
		}

		return $collection;
	}

	/**
	 * @throws SystemException
	 */
	private function buildReadyMessageQuery(string $queueId): Query
	{
		/** @var MessengerMessageTable $tableClass */
		$tableClass = $this->tableEntity->getDataClass();

		$query = $tableClass::query();

		$query
			->setSelect(['*'])
			->where('QUEUE_ID', '=', $queueId)
			->where('AVAILABLE_AT', '<=', new DateTime())
			// @todo Use synchronization primitives (after implementation) instead of status field to avoid
			->setOrder(['STATUS' => 'DESC', 'CREATED_AT' => 'ASC'])
		;

		return $query;
	}

	/**
	 * @internal
	 *
	 * @throws ArgumentException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function requeueStaleMessages(DateTime $thresholdDate): void
	{
		/** @var MessengerMessageTable $tableClass */
		$tableClass = $this->tableEntity->getDataClass();

		$tableClass::requeueStaleMessages($thresholdDate);
	}

	/**
	 * @throws PersistenceException
	 */
	public function save(MessageBox $messageBox): void
	{
		try
		{
			$result = $this->mapper->convertToOrm($messageBox)->save();
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), $e->getCode(), $e);
		}

		if ($result->isSuccess() && !$messageBox->getId())
		{
			$messageBox->setId($result->getId());
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to save message: ' . $result->getError()->getMessage());
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(MessageBox $message): void
	{
		try
		{
			$result = $this->mapper->convertToOrm($message)->delete();
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), $e->getCode(), $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to delete message: ' . $result->getError()->getMessage());
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function updateStatus(MessageBoxCollection $items, MessageStatus $status): void
	{
		if ($items->isEmpty())
		{
			return;
		}

		$ids = array_map(
			function (MessageBox $item) {
				return $item->getId();
			},
			$items->toArray(),
		);

		try
		{
			/** @var MessengerMessageTable $tableClass */
			$tableClass = $this->tableEntity->getDataClass();

			$result = $tableClass::updateMulti($ids, ['STATUS' => $status->value], true);
		}
		catch (SystemException $e)
		{
			throw new PersistenceException($e->getMessage(), $e->getCode(), $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to update status: ' . $result->getError()->getMessage());
		}
	}

	private function getEntityFromOrmItem($ormItem): ?MessageBox
	{
		try
		{
			return $this->mapper->convertFromOrm($ormItem);
		}
		catch (ArgumentException)
		{
			return null;
		}
	}
}
