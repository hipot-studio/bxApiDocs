<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

class GroupBlocks extends ContentBlock
{
	/** @var ContentBlock[] */
	protected array $items = [];

	public function getRendererName(): string
	{
		return 'GroupBlocks';
	}

	public function isFilled(): bool
	{
		return count($this->items) > 0;
	}

	public function addBlock(string $id, ContentBlock $item): self
	{
		$this->items[$id] = $item;

		return $this;
	}

	public function setBlocks(array $items): self
	{
		$this->items = $items;

		return $this;
	}

	public function getBlocks(): array
	{
		return $this->items;
	}

	protected function getProperties(): array
	{
		return [
			'blocks' => $this->getBlocks(),
		];
	}
}
