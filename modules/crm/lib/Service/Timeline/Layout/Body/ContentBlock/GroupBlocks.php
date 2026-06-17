<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

class GroupBlocks extends ContentBlock
{
	public const BORDER_TYPE_BASE = 'base';
	public const BORDER_TYPE_WARNING = 'warning';

	/** @var ContentBlock[] */
	protected array $items = [];

	protected string $borderType = self::BORDER_TYPE_BASE;

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

	public function setBorderType(string $borderType): self
	{
		$this->borderType = $borderType;

		return $this;
	}

	public function getBorderType(): string
	{
		return $this->borderType;
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
			'borderType' => $this->getBorderType(),
			'blocks' => $this->getBlocks(),
		];
	}
}
