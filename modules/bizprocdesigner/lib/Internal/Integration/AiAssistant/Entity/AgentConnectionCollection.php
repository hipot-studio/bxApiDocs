<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\BizprocDesigner\Internal\Entity\Collection\AbstractCollection;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * @extends AbstractCollection<AgentConnection>
 */
final class AgentConnectionCollection extends AbstractCollection implements Arrayable
{
	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof AgentConnection;
	}

	public function hasDestinationBlockWithId(string $id): bool
	{
		foreach ($this as $connection)
		{
			if ($connection->destinationBlockId === $id)
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
			if ($connection->sourceBlockId === $id)
			{
				return true;
			}
		}

		return false;
	}

	public function toArray(): array
	{
		return array_map(static fn(AgentConnection $connection) => $connection->toArray(), $this->items);
	}
}