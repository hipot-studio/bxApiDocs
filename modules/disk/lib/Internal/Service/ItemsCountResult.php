<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Main\Result;

class ItemsCountResult extends Result
{
	private const DATA_KEY_COUNT = 'count';

	public function setCount(int $count): void
	{
		$this->setData([self::DATA_KEY_COUNT => $count]);
	}

	public function getCount(): ?int
	{
		$data = $this->getData();
		if (!isset($data[self::DATA_KEY_COUNT]))
		{
			return null;
		}

		return (int)$data[self::DATA_KEY_COUNT];
	}

	public function hasCount(): bool
	{
		if (!$this->isSuccess())
		{
			return false;
		}

		return $this->getCount() !== null;
	}
}