<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity\Collection;

use Bitrix\BizprocDesigner\Internal\Entity\Connection;
use Bitrix\Main\Entity\EntityInterface;

/**
 * @extends AbstractEntityCollection<Connection>
 */
final class ConnectionCollection extends AbstractEntityCollection
{
	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof Connection;
	}

	protected function createEntityFromArray(array $data): EntityInterface
	{
		return Connection::createFromArray($data);
	}

	public function hasTargetBlockWithId(string $id): bool
	{
		foreach ($this as $connection)
		{
			if ($connection instanceof Connection && $connection->targetBlockId === $id)
			{
				return true;
			}
		}

		return false;
	}

	public function hasSourceBlockWithId(string $id): bool
	{
		foreach ($this as $connection)
		{
			if ($connection instanceof Connection && $connection->sourceBlockId === $id)
			{
				return true;
			}
		}

		return false;
	}

}
