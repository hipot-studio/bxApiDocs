<?php

namespace Bitrix\Im\Message;

use Bitrix\Main\Application;
use Bitrix\Im\Model\MessageUuidTable;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlHelper;
use Bitrix\Main\Type\DateTime;

class Uuid
{
	private const TTL = 3600;

	/** @var string */
	private $tableName;

	/** @var string */
	private $uuid;

	/** @var int */
	private $messageId;

	/** @var bool */
	private $messageLoaded = false;

	private ?DateTime $dateCreate = null;

	private ?DateTime $reservationDateCreate = null;

	/** @var Connection */
	protected $connection;

	/** @var SqlHelper */
	protected $sqlHelper;

	/**
	 * @param string $uuid UUID value.
	 */
	public function __construct(string $uuid)
	{
		$this->uuid = $uuid;
		$this->connection = Application::getInstance()->getConnection();
		$this->sqlHelper = $this->connection->getSqlHelper();
		$this->tableName = $this->sqlHelper->forSql(MessageUuidTable::getTableName());
	}

	public function getUuid(): string
	{
		return $this->uuid;
	}

	private function setMessageId(?int $messageId): void
	{
		$this->messageId = $messageId;
	}

	/**
	 * Method is trying to add a new UUID to the DB via INSERT IGNORE query.
	 * If there is already a record with the same UUID, then we will get false.
	 *
	 * @return bool
	 */
	public function add(): bool
	{
		$dateCreate = new DateTime();
		$preparedData = $this->sqlHelper->prepareInsert($this->tableName, [
			'DATE_CREATE' => $dateCreate,
			'UUID' => $this->uuid,
		]);

		$this->connection->queryExecute(
			$this->sqlHelper->getInsertIgnore(
				$this->sqlHelper->quote($this->tableName),
				" ({$preparedData[0]}) ",
				" VALUES ({$preparedData[1]})"
			)
		);
		$rowsAffected = $this->connection->getAffectedRowsCount();
		if ($rowsAffected > 0)
		{
			$this->dateCreate = $dateCreate;
			$this->reservationDateCreate = $dateCreate;
			$this->setMessageId(null);
			$this->messageLoaded = true;
		}

		return $rowsAffected > 0;
	}

	/**
	 * Validates UUIDv4 string.
	 *
	 * @return bool
	 */
	public static function validate(string $uuid): bool
	{
		$regex = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

		return preg_match($regex, $uuid) === 1;
	}

	/**
	 * Loads existing UUID records in one query.
	 *
	 * @param string[] $uuids
	 * @return array<string, self>
	 */
	public static function getExistingByUuids(array $uuids): array
	{
		$uuids = array_values(array_unique(array_filter($uuids, static function ($uuid): bool {
			return is_string($uuid) && self::validate($uuid);
		})));
		if (empty($uuids))
		{
			return [];
		}

		$records = MessageUuidTable::query()
			->setSelect(['UUID', 'MESSAGE_ID', 'DATE_CREATE'])
			->whereIn('UUID', $uuids)
			->fetchAll()
		;

		$services = [];
		foreach ($records as $record)
		{
			$service = new self($record['UUID']);
			$service->messageLoaded = true;
			$service->setMessageId(isset($record['MESSAGE_ID']) ? (int)$record['MESSAGE_ID'] : null);
			$service->dateCreate = $record['DATE_CREATE'] instanceof DateTime ? $record['DATE_CREATE'] : null;
			$services[$record['UUID']] = $service;
		}

		return $services;
	}

	/**
	 * Loads record from the table by UUID.
	 *
	 * @return void
	 */
	private function loadMessage(): void
	{
		$uuidRecord = MessageUuidTable::getRow([
			'select' => ['MESSAGE_ID', 'DATE_CREATE'],
			'filter' => [
				'=UUID' => $this->uuid,
			]
		]);

		if (is_null($uuidRecord))
		{
			return;
		}

		$this->messageLoaded = true;
		$this->setMessageId($uuidRecord['MESSAGE_ID']);
		$this->dateCreate = $uuidRecord['DATE_CREATE'];
	}

	/**
	 * Returns message ID.
	 *
	 * @return int|null
	 */
	public function getMessageId(): ?int
	{
		if (!$this->messageLoaded)
		{
			$this->loadMessage();
		}

		return $this->messageId;
	}

	/**
	 * Updates message_id for the certain UUID.
	 *
	 * @param int $messageId Message id.
	 */
	public function updateMessageId(int $messageId): void
	{
		if ($this->messageLoaded && $this->messageId === $messageId)
		{
			return;
		}

		if ($this->reservationDateCreate instanceof DateTime)
		{
			$uuid = $this->sqlHelper->forSql($this->uuid);
			$reservationDateSql = $this->sqlHelper->convertToDbDateTime($this->reservationDateCreate);
			$this->connection->queryExecute(
				"UPDATE {$this->tableName} SET MESSAGE_ID = {$messageId} "
				. "WHERE UUID = '{$uuid}' AND MESSAGE_ID IS NULL AND DATE_CREATE = {$reservationDateSql}"
			);
			if ($this->connection->getAffectedRowsCount() <= 0)
			{
				return;
			}
		}
		else
		{
			MessageUuidTable::update($this->uuid, [
				'MESSAGE_ID' => $messageId
			]);
		}
		$this->setMessageId($messageId);
		$this->messageLoaded = true;
	}

	/**
	 * Deletes UUID record from the DB table.
	 *
	 * @return bool
	 */
	public function delete(): bool
	{
		$deleteResult = MessageUuidTable::delete($this->uuid);

		return $deleteResult->isSuccess();
	}

	/**
	 * Deletes an unfinished UUID reservation without touching a completed mapping.
	 */
	public function deleteIfPending(): bool
	{
		if (!$this->reservationDateCreate instanceof DateTime)
		{
			return false;
		}

		$uuid = $this->sqlHelper->forSql($this->uuid);
		$reservationDateSql = $this->sqlHelper->convertToDbDateTime($this->reservationDateCreate);
		$this->connection->queryExecute(
			"DELETE FROM {$this->tableName} "
			. "WHERE UUID = '{$uuid}' AND MESSAGE_ID IS NULL AND DATE_CREATE = {$reservationDateSql}"
		);

		return $this->connection->getAffectedRowsCount() > 0;
	}

	/**
	 * Updates UUID record with current date and time, if it is expired.
	 *
	 * @return bool
	 */
	public function updateIfExpired(): bool
	{
		$now = new DateTime();
		if (!$this->messageLoaded)
		{
			$this->loadMessage();
		}
		if (
			!$this->dateCreate instanceof DateTime
			|| ($now->getTimestamp() - $this->dateCreate->getTimestamp()) <= self::TTL
		)
		{
			return false;
		}

		$expiredAt = (clone $now)->add('-' . self::TTL . ' seconds');
		$uuid = $this->sqlHelper->forSql($this->uuid);
		$nowSql = $this->sqlHelper->convertToDbDateTime($now);
		$expiredAtSql = $this->sqlHelper->convertToDbDateTime($expiredAt);

		$this->connection->queryExecute(
			"UPDATE {$this->tableName} SET DATE_CREATE = {$nowSql} "
			. "WHERE UUID = '{$uuid}' AND MESSAGE_ID IS NULL AND DATE_CREATE < {$expiredAtSql}"
		);
		if ($this->connection->getAffectedRowsCount() <= 0)
		{
			return false;
		}

		$this->setMessageId(null);
		$this->messageLoaded = true;
		$this->dateCreate = $now;
		$this->reservationDateCreate = $now;

		return true;
	}

	/**
	 * Atomically verifies and refreshes an owned reservation before side effects.
	 */
	public function renewReservation(): bool
	{
		if (!$this->reservationDateCreate instanceof DateTime)
		{
			return false;
		}

		$renewedAt = (new DateTime())->add('+1 second');
		if ($renewedAt->getTimestamp() <= $this->reservationDateCreate->getTimestamp())
		{
			$renewedAt = (clone $this->reservationDateCreate)->add('+1 second');
		}

		$uuid = $this->sqlHelper->forSql($this->uuid);
		$renewedAtSql = $this->sqlHelper->convertToDbDateTime($renewedAt);
		$reservationDateSql = $this->sqlHelper->convertToDbDateTime($this->reservationDateCreate);
		$this->connection->queryExecute(
			"UPDATE {$this->tableName} SET DATE_CREATE = {$renewedAtSql} "
			. "WHERE UUID = '{$uuid}' AND MESSAGE_ID IS NULL AND DATE_CREATE = {$reservationDateSql}"
		);
		if ($this->connection->getAffectedRowsCount() <= 0)
		{
			return false;
		}

		$this->reservationDateCreate = $renewedAt;
		$this->dateCreate = $renewedAt;

		return true;
	}

	/**
	 * Agent. Deletes old records (older than 30 days) from the table.
	 *
	 * @return string
	 */
	public static function cleanOldRecords(): string
	{
		$daysBeforeExpire = 31;
		$connection = Application::getInstance()->getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$tableName = $sqlHelper->forSql(MessageUuidTable::getTableName());
		$expiredDateTime = (new DateTime())->add("-$daysBeforeExpire days");
		$expiredDateTimePrepared = $sqlHelper->convertToDbDateTime($expiredDateTime);

		$query = "DELETE FROM $tableName WHERE DATE_CREATE < $expiredDateTimePrepared;";
		$connection->queryExecute($query);

		return __METHOD__. '();';
	}
}
