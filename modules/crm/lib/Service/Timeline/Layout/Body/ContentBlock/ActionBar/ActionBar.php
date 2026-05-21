<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ActionBar;

use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

class ActionBar extends ContentBlock
{
	private ?string $title = null;

	/** @var ActionBarItem[] */
	private array $barItems = [];

	public function getRendererName(): string
	{
		return 'ActionBar';
	}

	public function addItem(string $id, ActionBarItem $item): self
	{
		$this->barItems[$id] = $item;

		return $this;
	}

	public function isFilled(): bool
	{
		return count($this->barItems) > 0;
	}

	public function getTitle(): ?string
	{
		return $this->title;
	}

	public function setTitle(?string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function getItems(): array
	{
		return $this->barItems;
	}

	public function setItems(array $items): self
	{
		$this->barItems = $items;

		return $this;
	}

	protected function getProperties(): array
	{
		return [
			'title' => $this->getTitle(),
			'items' => $this->getItems(),
		];
	}
}
