<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Registry;

use Bitrix\Tasks\Internals\Task\ElapsedTimeObject;
use Bitrix\Tasks\Internals\Task\ElapsedTimeTable;
use Bitrix\Tasks\Internals\Trait\SingletonTrait;

final class ElapsedTimeRegistry
{
	use SingletonTrait;

	private array $storage = [];

	public function get(int $elapsedTimeId): ?ElapsedTimeObject
	{
		if (!isset($this->storage[$elapsedTimeId]))
		{
			$this->load([$elapsedTimeId]);
		}

		return $this->storage[$elapsedTimeId] ?? null;
	}

	public function load(array $elapsedTimeIds): self
	{
		if (empty($elapsedTimeIds))
		{
			return $this;
		}

		$elapsedTimeIds = array_diff(array_unique($elapsedTimeIds), array_keys($this->storage));

		if (empty($elapsedTimeIds))
		{
			return $this;
		}

		$select = [
			'ID',
			'USER_ID',
		];

		$results = ElapsedTimeTable::query()
			->setSelect($select)
			->whereIn('ID', $elapsedTimeIds)
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
