<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\BizprocDesigner\Internal\Entity\Collection\AbstractCollection;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * @extends AbstractCollection<AgentBlock>
 */
final class AgentBlockCollection extends AbstractCollection implements Arrayable
{
	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof AgentBlock;
	}

	public function getIds(): array
	{
		$ids = [];
		foreach ($this as $block)
		{
			$ids[] = $block->id;
		}

		return $ids;
	}

	public function toArray(): array
	{
		return array_map(static fn(AgentBlock $block) => $block->toArray(), $this->items);
	}

	public function getById(string $id): ?AgentBlock
	{
		foreach ($this->items as $block)
		{
			if ($block->id === $id)
			{
				return $block;
			}
		}

		return null;
	}
}