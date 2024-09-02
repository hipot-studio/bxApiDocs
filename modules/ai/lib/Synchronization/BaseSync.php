<?php

declare(strict_types = 1);

namespace Bitrix\AI\Synchronization;

use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

abstract class BaseSync implements SyncInterface
{
	protected DataManager $dataManager;

	/**
	 * Return DataManager
	 *
	 * @return DataManager
	 */
	abstract protected function getDataManager(): DataManager;

	protected function getQueryBuilder(): Query
	{
		return $this->getDataManager()::query();
	}

	protected function add(array $fields): AddResult
	{
		return $this->getDataManager()::add($fields);
	}

	protected function delete(string $id): DeleteResult
	{
		return $this->getDataManager()::delete($id);
	}

	protected function update(string $id, array $fields): UpdateResult
	{
		return $this->getDataManager()::update($id, $fields);
	}

	/**
	 * Returns ids by filter
	 *
	 * @param array $filter
	 *
	 * @return array|null
	 */
	protected function getIdsByFilter(array $filter): array|null
	{
		$query = $this->getQueryBuilder()->setSelect(['ID']);
		$query->setFilter($filter);
		$ids = $query->fetchAll();

		return array_column($ids, 'ID');
	}

	/**
	 * Return result of add or update
	 *
	 * @param array $fields
	 *
	 * @return AddResult|UpdateResult
	 */
	protected function addOrUpdate(array $fields): AddResult|UpdateResult
	{
		$fields = array_change_key_case($fields, CASE_UPPER);
		$filterExists = [];
		$exists = null;

		if (array_key_exists('CODE', $fields))
		{
			$filterExists['=CODE'] = $fields['CODE'];
		}

		if (!empty($filterExists))
		{
			$exists = $this->getByFilter($filterExists);
		}

		if (is_null($exists))
		{
			return $this->add($fields);
		}

		if ($exists['HASH'] === ($fields['HASH'] ?? null))
		{
			return $this->getFakeUpdateResult($exists['ID']);
		}

		$fields['DATE_MODIFY'] = new DateTime();

		return $this->update($exists['ID'], $fields);
	}

	protected function getFakeUpdateResult(string $id): UpdateResult
	{
		$result = new UpdateResult();
		$result->setPrimary(['ID' => $id]);
		return $result;
	}

	protected function getByFilter(array $filter): array|null
	{
		$item = $this->getQueryBuilder()
			->setSelect(['ID', 'HASH'])
			->setFilter($filter)
			->setLimit(1)
			->fetch()
		;

		return is_array($item) ? $item : null;
	}

	protected function log(string $message): void
	{
		AddMessage2Log($message);
	}

	/**
	 * Synchronization table with items by filter
	 *
	 * @param array $items
	 * @param array $filter
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function sync(array $items, array $filter = []): void
	{
		$oldIds = $this->getIdsByFilter($filter);
		$currentIds = [];
		foreach ($items as $item)
		{
			$result = $this->addOrUpdate($item);
			if ($result->isSuccess())
			{
				$currentIds[] = $result->getId();
			}
			else
			{
				$this->log('AI_DB_SYNC_ERROR: ' . implode('; ', $result->getErrorMessages()));
			}
		}

		$idsForDelete = array_diff($oldIds, $currentIds);
		foreach ($idsForDelete as $id)
		{
			$this->delete($id);
		}
	}
}
