<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internals\Steppers;

use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internals\ObjectNameKey;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Update\Stepper;
use Psr\Log\LoggerInterface;

/**
 * Fills NAME_KEY of objects stored before the column appeared.
 */
class ObjectNameKeyUpdater extends Stepper
{
	protected static $moduleId = 'disk';
	protected const ROWS_PER_STEP = 100;
	// Base Stepper pauses after a step for its duration times the coefficient once it runs longer than the threshold.
	// A faster step keeps the agent interval: a pause rounded down to zero seconds would not move the agent at all.
	private const PAUSE_THRESHOLD_TIME = 0.1;
	private const PAUSE_COEFFICIENT = 10.0;

	private readonly LoggerInterface $logger;

	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger ?? LoggerFactory::create(feature: 'object name key updater');
	}

	public function execute(array &$option): bool
	{
		if (!isset($option['maxId']))
		{
			$option['maxId'] = $this->getMaxId();
			$option['lastProcessedId'] = 0;
			$option['steps'] = 0;
			// Progress is measured by ID: counting rows without the key reads the whole table.
			$option['count'] = $option['maxId'];
		}
		// Every row moves the unique index too, so the stepper gives way to the portal after each step.
		$option['thresholdTime'] = self::PAUSE_THRESHOLD_TIME;
		$option['delayCoefficient'] = self::PAUSE_COEFFICIENT;

		$rows = ObjectTable::getList([
			'select' => ['ID', 'NAME', 'PARENT_ID'],
			'filter' => [
				'=NAME_KEY' => null,
				'>ID' => (int)$option['lastProcessedId'],
				'<=ID' => (int)$option['maxId'],
			],
			'order' => ['ID' => 'ASC'],
			'limit' => self::ROWS_PER_STEP,
		])->fetchAll();

		if (empty($rows))
		{
			return self::FINISH_EXECUTION;
		}

		$this->fillNameKeys($rows);
		$option['lastProcessedId'] = (int)end($rows)['ID'];
		$option['steps'] = $option['lastProcessedId'];

		return count($rows) < self::ROWS_PER_STEP ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

	/**
	 * One statement per step: a statement per row is a commit and a binary log entry of its own.
	 */
	private function fillNameKeys(array $rows): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$cases = [];
		$ids = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$ids[] = $id;
			$cases[] = "WHEN {$id} THEN '" . $helper->forSql(ObjectNameKey::of((string)$row['NAME'])) . "'";
		}

		try
		{
			$connection->queryExecute(
				'UPDATE b_disk_object SET NAME_KEY = CASE ID ' . implode(' ', $cases) . ' END'
				. ' WHERE ID IN (' . implode(', ', $ids) . ') AND NAME_KEY IS NULL'
			);
		}
		catch (DuplicateEntryException)
		{
			// One taken key fails the whole statement; row by row the other rows still get their keys.
			foreach ($rows as $row)
			{
				$this->fillNameKey($row);
			}
		}
	}

	private function fillNameKey(array $row): void
	{
		$sql = new SqlExpression(
			'UPDATE b_disk_object SET NAME_KEY = ?s WHERE ID = ?i AND NAME_KEY IS NULL',
			ObjectNameKey::of((string)$row['NAME']),
			(int)$row['ID'],
		);

		try
		{
			Application::getConnection()->queryExecute($sql->compile());
		}
		catch (DuplicateEntryException)
		{
			// The folder already has an object with the same name key; the row keeps NULL and is not renamed.
			$this->logger->warning('Name key of object {objectId} is taken in folder {parentId}', [
				'objectId' => (int)$row['ID'],
				'parentId' => (int)$row['PARENT_ID'],
			]);
		}
	}

	/**
	 * Objects added after the column appeared get the key on write, so the stepper can stop at the current last ID.
	 */
	private function getMaxId(): int
	{
		$row = ObjectTable::getRow([
			'select' => ['ID'],
			'order' => ['ID' => 'DESC'],
		]);

		return (int)($row['ID'] ?? 0);
	}
}
