<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Registry;

use Bitrix\Tasks\Internals\Task\Result\Result;
use Bitrix\Tasks\Internals\Task\Result\ResultTable;
use Bitrix\Tasks\Internals\Trait\SingletonTrait;

final class ResultRegistry
{
	use SingletonTrait;

	private array $storage = [];

	public function get(int $resultId): ?Result
	{
		if (!isset($this->storage[$resultId]))
		{
			$this->load([$resultId]);
		}

		return $this->storage[$resultId] ?? null;
	}

	public function load(array $resultIds): self
	{
		if (empty($resultIds))
		{
			return $this;
		}

		$resultIds = array_diff(array_unique($resultIds), array_keys($this->storage));

		if (empty($resultIds))
		{
			return $this;
		}

		$select = [
			'ID',
			'TASK_ID',
			'CREATED_BY',
			'COMMENT_ID',
			'CREATED_AT',
			'UPDATED_AT',
			'STATUS',
			'TEXT',
			'MESSAGE.MESSAGE_ID'
		];

		$results = ResultTable::query()
			->setSelect($select)
			->whereIn('ID', $resultIds)
			->fetchCollection();

		if ($results->isEmpty())
		{
			return $this;
		}

		foreach ($results as $result)
		{
			$this->storage[$result->getId()] = $result;
		}

		return $this;
	}
}
