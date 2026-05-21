<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Main\ArgumentTypeException;

class LineOfTextBlocks extends ContentBlock
{
	protected array $blocks = [];
	protected ?string $delimiter = null;
	protected ?LineOfTextBlocksButton $button = null;

	public function getRendererName(): string
	{
		return 'LineOfTextBlocks';
	}

	/**
	 * @return ContentBlock[]
	 */
	public function getContentBlocks(): array
	{
		return $this->blocks;
	}

	public function getContentBlocksCount(): int
	{
		return count($this->blocks);
	}

	public function isEmpty(): bool
	{
		return empty($this->blocks);
	}

	public function setDelimiter(?string $delimiter): self
	{
		$this->delimiter = $delimiter;

		return $this;
	}

	public function addContentBlock(string $id, ContentBlock $textContentBlock): self
	{
		if (!$this->isAvailableContentBlock($textContentBlock))
		{
			throw new ArgumentTypeException(
				'contentBlock',
				Text::class
				. '|' . Link::class
				. '|' . Date::class
				. '|' . Money::class
				. '|' . ItemSelector::class
				. '|' . ClientCommunication::class
				. '|' . ClientMark::class
			);
		}

		if (is_null($textContentBlock->getSort()))
		{
			$textContentBlock->setSort(count($this->blocks) + 1);
		}

		$this->blocks[$id] = $textContentBlock;

		return $this;
	}

	private function isAvailableContentBlock(ContentBlock $contentBlock): bool
	{
		return
			$contentBlock instanceof self
			|| ($contentBlock instanceof Text)
			|| ($contentBlock instanceof Link)
			|| ($contentBlock instanceof Date)
			|| ($contentBlock instanceof Money)
			|| ($contentBlock instanceof ItemSelector)
			|| ($contentBlock instanceof ClientCommunication)
			|| ($contentBlock instanceof ClientMark)
		;
	}

	/**
	 * @param Text[]|EditableDate[] $blocks
	 *
	 * @return $this
	 *
	 * @throws ArgumentTypeException
	 */
	public function setContentBlocks(array $blocks): self
	{
		$this->blocks = [];
		$currentSort = 0;
		foreach ($blocks as $id => $block)
		{
			if (is_null($block->getSort()))
			{
				$block->setSort($currentSort++);
			}
			$this->addContentBlock((string)$id, $block);
		}

		return $this;
	}

	public function setTextColor(?string $color): self
	{
		foreach ($this->blocks as $block)
		{
			if (!$block instanceof TextPropertiesInterface)
			{
				continue;
			}

			$block->setColor($color);
		}

		return $this;
	}

	public function setButton(?LineOfTextBlocksButton $button): self
	{
		$this->button = $button;

		return $this;
	}

	protected function getProperties(): array
	{
		return [
			'blocks' => $this->getContentBlocks(),
			'delimiter' => $this->delimiter,
			'button' => $this->button,
		];
	}
}
