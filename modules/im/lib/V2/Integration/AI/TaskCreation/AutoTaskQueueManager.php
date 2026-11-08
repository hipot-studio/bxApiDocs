<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

use Bitrix\Im\Model\AiTaskQueueTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

class AutoTaskQueueManager
{
	/**
	 * Matches the ai module queue job TTL (@see \Bitrix\AI\QueueJob::TTL_SECONDS): a record
	 * older than that cannot belong to a live operation - its job has either completed or
	 * expired - so it is treated as absent and replaced on the next attempt instead of
	 * blocking the entity forever.
	 */
	private const RECORD_TTL_SECONDS = 14400;

	private static ?self $instance = null;

	private function __construct() {}

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	public function inQueue(array $messageIds, AutoTaskType $type = AutoTaskType::TaskCreation): bool
	{
		$row = $this->getRow($messageIds, $type);

		return $row !== null && !$this->isExpired($row);
	}

	/**
	 * Inserts a dedup/state record for the selection, replacing an expired one if present.
	 *
	 * MUST be called while holding the per-entity connection lock (see
	 * SingleMessageCreator::sendAiQuery / MultipleMessagesCreator::registerBatch): the
	 * expired-record replacement below is a non-atomic read-delete-insert and is only
	 * race-safe under that external lock. The insert itself is duplicate-safe via the
	 * (ENTITY_TYPE, ENTITY_ID) unique index.
	 */
	public function add(array $messageIds, AutoTaskType $type = AutoTaskType::TaskCreation, ?array $params = null): void
	{
		$row = $this->getRow($messageIds, $type);
		if ($row !== null && $this->isExpired($row))
		{
			AiTaskQueueTable::delete($row['id']);
		}

		AiTaskQueueTable::multiplyInsertWithoutDuplicate(
			[[
				'ENTITY_TYPE' => $type->value,
				'ENTITY_ID' => $type->buildEntityId($messageIds),
				'PARAMS' => $params === null ? null : Json::encode($params),
				'DATE_CREATE' => new DateTime(),
			]],
			['DEADLOCK_SAFE' => true, 'UNIQUE_FIELDS' => ['ENTITY_TYPE', 'ENTITY_ID']]
		);
	}

	public function delete(array $messageIds, AutoTaskType $type = AutoTaskType::TaskCreation): void
	{
		AiTaskQueueTable::deleteBatch([
			'=ENTITY_TYPE' => $type->value,
			'=ENTITY_ID' => $type->buildEntityId($messageIds),
		]);
	}

	/**
	 * @return array{id: int, params: ?array, dateCreate: ?DateTime}|null
	 */
	public function getRow(array $messageIds, AutoTaskType $type): ?array
	{
		$row = AiTaskQueueTable::query()
			->setSelect(['ID', 'PARAMS', 'DATE_CREATE'])
			->where('ENTITY_TYPE', $type->value)
			->where('ENTITY_ID', $type->buildEntityId($messageIds))
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		return [
			'id' => (int)$row['ID'],
			'params' => $this->decodeParams($row['PARAMS'] ?? null),
			'dateCreate' => $row['DATE_CREATE'] instanceof DateTime ? $row['DATE_CREATE'] : null,
		];
	}

	public function updateParamsById(int $rowId, array $params): void
	{
		AiTaskQueueTable::update($rowId, ['PARAMS' => Json::encode($params)]);
	}

	private function isExpired(array $row): bool
	{
		$dateCreate = $row['dateCreate'];

		return !($dateCreate instanceof DateTime)
			|| (time() - $dateCreate->getTimestamp()) > self::RECORD_TTL_SECONDS;
	}

	private function decodeParams(?string $rawParams): ?array
	{
		if ((string)$rawParams === '')
		{
			return null;
		}

		try
		{
			$params = Json::decode($rawParams);
		}
		catch (ArgumentException)
		{
			return null;
		}

		return is_array($params) ? $params : null;
	}
}
