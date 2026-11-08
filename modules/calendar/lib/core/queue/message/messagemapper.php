<?php

namespace Bitrix\Calendar\Core\Queue\Message;

use Bitrix\Calendar\Core;
use Bitrix\Calendar\Core\Mappers\Mapper;
use Bitrix\Calendar\Internals\EO_QueueMessage;
use Bitrix\Calendar\Internals\QueueMessageTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Exception;

class MessageMapper extends Mapper
{
	public function getUnroutedRows(int $limit, ?int $maxId = null, int $afterId = 0): array
	{
		$connection = Application::getConnection();
		$sql = $maxId === null
			? (new SqlExpression(
				'SELECT ID, MESSAGE FROM ?# WHERE STATUS = ?s AND ID > ?i ORDER BY ID ASC',
				QueueMessageTable::getTableName(),
				'new',
				$afterId,
			))->compile()
			: (new SqlExpression(
				'SELECT ID, MESSAGE FROM ?# WHERE STATUS = ?s AND ID > ?i AND ID <= ?i ORDER BY ID ASC',
				QueueMessageTable::getTableName(),
				'new',
				$afterId,
				$maxId,
			))->compile();
		$sql = $connection->getSqlHelper()->getTopSql($sql, $limit);

		return $connection->query($sql)->fetchAll();
	}

	public function getMaxUnroutedId(int $afterId = 0): ?int
	{
		$sql = (new SqlExpression(
			'SELECT MAX(ID) AS MAX_ID FROM ?# WHERE STATUS = ?s AND ID > ?i',
			QueueMessageTable::getTableName(),
			'new',
			$afterId,
		))->compile();
		$row = Application::getConnection()->query($sql)->fetch();

		return isset($row['MAX_ID']) ? (int)$row['MAX_ID'] : null;
	}

	public function decodeRawRow(array $row): Message
	{
		$data = Json::decode((string)$row['MESSAGE']);
		if (!is_array($data))
		{
			throw new ArgumentException('Queue message payload must be an array');
		}

		return (new Message())
			->setId((int)$row['ID'])
			->setBody($data[Dictionary::MESSAGE_PARTS['body']] ?? [])
			->setHeaders($data[Dictionary::MESSAGE_PARTS['headers']] ?? [])
			->setProperties($data[Dictionary::MESSAGE_PARTS['properties']] ?? [])
		;
	}

	/**
	 * The drain flips the status away from new to take a row for itself, so the same conditional
	 * update is what proves this pass got to the row first. Zero affected rows mean the drain owns
	 * it now and it has to be left alone.
	 */
	public function claimForRouting(int $id): bool
	{
		$connection = Application::getConnection();
		$connection->queryExecute(
			(new SqlExpression(
				'UPDATE ?# SET STATUS = ?s WHERE ID = ?i AND STATUS = ?s',
				QueueMessageTable::getTableName(),
				'routed',
				$id,
				'new',
			))->compile(),
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * Only a row still at new was picked up by this pass, so the same condition the routing claim
	 * carries keeps the quarantine - and the attempt it counts - off a row the drain has taken.
	 */
	public function quarantine(int $id): void
	{
		$connection = Application::getConnection();
		$sql = (new SqlExpression(
			'UPDATE ?# SET STATUS = ?s, ATTEMPTS = ATTEMPTS + 1 WHERE ID = ?i AND STATUS = ?s',
			QueueMessageTable::getTableName(),
			'quarantine',
			$id,
			'new',
		))->compile();
		$connection->queryExecute($sql);
	}

	public function deleteById(int $id): void
	{
		$sql = (new SqlExpression(
			'DELETE FROM ?# WHERE ID = ?i',
			QueueMessageTable::getTableName(),
			$id,
		))->compile();
		Application::getConnection()->queryExecute($sql);
	}

	/**
	 * @return string
	 */
	protected function getEntityClass(): string
	{
		return Message::class;
	}

	/**
	 * @param array $params
	 *
	 * @return Result
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function getDataManagerResult(array $params): Result
	{
		$params['select'] ??= ["*"];

		return QueueMessageTable::getList($params);
	}

	/**
	 * @param array $filter
	 *
	 * @return Message|null
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function getOneEntityByFilter(array $filter): ?object
	{
		/** @var EO_QueueMessage $messageData */
		$messageData = $this->getDataManagerResult([
			'filter' => $filter,
			'select' => ['*'],
			'limit' => 1,
		])->fetchObject();

		if ($messageData)
		{
			return $this->convertToObject($messageData);
		}

		return null;
	}

	/**
	 * @param EO_QueueMessage $objectEO
	 *
	 * @return Message|null
	 */
	protected function convertToObject($objectEO): ?Core\Base\EntityInterface
	{
		return (new BuilderMessageFromDataManager($objectEO))->build();
	}

	/**
	 * @return string
	 */
	protected function getEntityName(): string
	{
		return 'queueMessage';
	}

	/**
	 * @param Message $entity
	 * @param array $params
	 *
	 * @return Message|null
	 *
	 * @throws Core\Base\BaseException
	 * @throws Exception
	 */
	protected function createEntity($entity, array $params = []): ?Core\Base\EntityInterface
	{
		$data = $this->convertToArray($entity);
		$data['DATE_CREATE'] = new DateTime();
		$result = QueueMessageTable::add($data);
		if ($result->isSuccess())
		{
			return $entity->setId($result->getId());
		}

		throw new Core\Base\BaseException('Error of create Queue message', 400);
	}

	/**
	 * @param Message $entity
	 * @param array $params
	 *
	 * @return Core\Base\EntityInterface|null
	 *
	 * @throws Core\Base\BaseException
	 * @throws Exception
	 */
	protected function updateEntity($entity, array $params = []): ?Core\Base\EntityInterface
	{
		$data = $this->convertToArray($entity);
		$result = QueueMessageTable::update($entity->getId(), $data);
		if ($result->isSuccess())
		{
			return $entity;
		}

		throw new Core\Base\BaseException('Error of update Queue message', 400);
	}

	/**
	 * @param Message $entity
	 * @param array $params
	 *
	 * @return Core\Base\EntityInterface|null
	 *
	 * @throws Core\Base\BaseException
	 * @throws Exception
	 */
	protected function deleteEntity(Core\Base\EntityInterface $entity, array $params): ?Core\Base\EntityInterface
	{
		$result = QueueMessageTable::delete($entity->getId());
		if ($result->isSuccess())
		{
			return null;
		}

		throw new Core\Base\BaseException('Error of delete Queue message');
	}

	/**
	 * @param Message $entity
	 *
	 * @return array
	 */
	private function convertToArray(Message $entity): array
	{
		return [
			'MESSAGE' => [
				Dictionary::MESSAGE_PARTS['body'] => $entity->getBody(),
				Dictionary::MESSAGE_PARTS['headers'] => $entity->getHeaders(),
				Dictionary::MESSAGE_PARTS['properties'] => $entity->getProperties(),
			],
		];
	}
}
