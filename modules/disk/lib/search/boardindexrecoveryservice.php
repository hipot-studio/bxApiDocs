<?php

declare(strict_types=1);

namespace Bitrix\Disk\Search;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\Index\ObjectExtendedIndexTable;
use Bitrix\Disk\Search\Messenger\IndexBoardMessage;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;

final class BoardIndexRecoveryService
{
	private const BATCH_SIZE = 100;
	private const WAITING_TTL = 3600;

	private \Closure $featureEnabled;
	private \Closure $clock;
	private \Closure $candidateLoader;
	private \Closure $reserver;
	private \Closure $boardLoader;
	private \Closure $sender;
	private LoggerInterface $logger;

	public function __construct(
		?\Closure $featureEnabled = null,
		?\Closure $clock = null,
		?\Closure $candidateLoader = null,
		?\Closure $reserver = null,
		?\Closure $boardLoader = null,
		?\Closure $sender = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->featureEnabled = $featureEnabled ?? static fn(): bool => Configuration::isBoardIndexEnabled()
			&& Configuration::allowUseExtendedFullText();
		$this->clock = $clock ?? static fn(): DateTime => new DateTime();
		$this->candidateLoader = $candidateLoader ?? $this->loadCandidates(...);
		$this->reserver = $reserver ?? $this->reserve(...);
		$this->boardLoader = $boardLoader ?? $this->loadCurrentBoard(...);
		$this->sender = $sender ?? static function (int $objectId, int $globalContentVersion): void {
			(new IndexBoardMessage($objectId, $globalContentVersion))->schedule();
		};
		$this->logger = $logger ?? ServiceLocator::getInstance()->get('disk.boardIndexingLogger');
	}

	public function recover(): int
	{
		if (!(($this->featureEnabled)()))
		{
			return 0;
		}

		$now = ($this->clock)();
		$cutoff = DateTime::createFromTimestamp($now->getTimestamp() - self::WAITING_TTL);
		$candidates = ($this->candidateLoader)($cutoff, self::BATCH_SIZE);
		$scheduled = 0;

		foreach ($candidates as $candidate)
		{
			$objectId = (int)$candidate['OBJECT_ID'];
			$expectedUpdateTime = $candidate['UPDATE_TIME'];
			if (!(($this->reserver)($objectId, $expectedUpdateTime, $now)))
			{
				continue;
			}

			try
			{
				$board = ($this->boardLoader)($objectId);
				if ($board === null)
				{
					continue;
				}

				($this->sender)($board['objectId'], $board['globalContentVersion']);
				$scheduled++;
			}
			catch (\Throwable $exception)
			{
				$this->logger->warning(
					'Board index recovery scheduling failed: objectId={objectId}, category={category}',
					[
						'objectId' => $objectId,
						'category' => $exception::class,
					],
				);
			}
		}

		return $scheduled;
	}

	/**
	 * @return list<array{OBJECT_ID: int, UPDATE_TIME: DateTime}>
	 */
	private function loadCandidates(DateTime $cutoff, int $limit): array
	{
		return ObjectExtendedIndexTable::query()
			->setSelect(['OBJECT_ID', 'UPDATE_TIME'])
			->where('STATUS', ObjectExtendedIndexTable::STATUS_BOARD_WAITING)
			->where('UPDATE_TIME', '<', $cutoff)
			->setOrder([
				'UPDATE_TIME' => 'ASC',
				'OBJECT_ID' => 'ASC',
			])
			->setLimit($limit)
			->fetchAll()
		;
	}

	private function reserve(int $objectId, DateTime $expectedUpdateTime, DateTime $now): bool
	{
		$entity = ObjectExtendedIndexTable::getEntity();
		$connection = $entity->getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$update = $sqlHelper->prepareUpdate(
			ObjectExtendedIndexTable::getTableName(),
			['UPDATE_TIME' => $now],
		);
		$filter = Query::filter()
			->where('OBJECT_ID', $objectId)
			->where('STATUS', ObjectExtendedIndexTable::STATUS_BOARD_WAITING)
			->where('UPDATE_TIME', $expectedUpdateTime)
		;
		$where = Query::buildFilterSql($entity, $filter);
		$tableName = $sqlHelper->quote(ObjectExtendedIndexTable::getTableName());

		$connection->queryExecute("UPDATE {$tableName} SET {$update[0]} WHERE {$where}", $update[1]);

		return $connection->getAffectedRowsCount() === 1;
	}

	/**
	 * @return array{objectId: int, globalContentVersion: int}|null
	 */
	private function loadCurrentBoard(int $objectId): ?array
	{
		$file = File::loadById($objectId)?->getRealObject();
		if (
			!$file instanceof File
			|| $file->isDeleted()
			|| (int)$file->getTypeFile() !== TypeFile::BOARD
		)
		{
			return null;
		}

		return [
			'objectId' => (int)$file->getId(),
			'globalContentVersion' => (int)$file->getGlobalContentVersion(),
		];
	}
}
