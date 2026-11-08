<?php

namespace Bitrix\Tasks\Kanban\Sort\Item;

use Bitrix\Main\Localization\Loc;
use CUtil;

class MenuItem
{
	public const SORT_ASC = 'asc';
	public const SORT_DESC = 'desc';
	public const SORT_ACTUAL = 'actual';

	public const TYPE_SUB = 'sub';

	protected string $tabId = '';
	protected string $html = '';
	protected string $className = '';
	protected string $onClick = '';
	protected bool $delimiter = false;
	protected bool $focusable = true;
	protected array $params = [];

	protected ItemCollection $items;


	public function __construct()
	{
		$this->init();
	}

	public function getClassName(): string
	{
		return $this->className;
	}

	public function getOnClick(): string
	{
		return $this->onClick;
	}

	public function getParams(bool $raw = false): array|string
	{
		if ($raw)
		{
			return $this->params;
		}

		if (!empty($this->params))
		{
			return CUtil::PhpToJSObject($this->params);
		}

		return '';
	}

	public function getTabId(): string
	{
		return $this->tabId;
	}

	public function getHtml(): string
	{
		return $this->html;
	}

	public function isFocusable(): bool
	{
		return $this->focusable;
	}

	public function setClassName(string $className): static
	{
		$this->className = $className;
		return $this;
	}

	public function setOnClick(string $onClick): static
	{
		$this->onClick = $onClick;
		return $this;
	}

	public function setTabId(string $tabId): static
	{
		$this->tabId = $tabId;
		return $this;
	}

	public function setHtml(string $html): static
	{
		$this->html = $html;
		return $this;
	}

	// main.popup draws a line instead of an item, so a delimiter carries no content of its own
	public function setDelimiter(): static
	{
		$this->delimiter = true;
		return $this;
	}

	public function setFocusable(bool $focusable): static
	{
		$this->focusable = $focusable;
		return $this;
	}

	public function setType(string $type): static
	{
		if ($type !== static::TYPE_SUB)
		{
			return $this;
		}

		$this->params['type'] = $type;
		return $this;
	}

	public function setOrder(string $order): static
	{
		if (!in_array($order, [static::SORT_ASC, static::SORT_DESC, static::SORT_ACTUAL], true))
		{
			return $this;
		}

		$this->params['order'] = $order;
		return $this;
	}

	public function setField(string $field): static
	{
		if ($field === '')
		{
			return $this;
		}

		$this->params['field'] = $field;
		return $this;
	}

	public function setDirection(string $direction): static
	{
		if (!in_array($direction, [static::SORT_ASC, static::SORT_DESC], true))
		{
			return $this;
		}

		$this->params['direction'] = $direction;
		return $this;
	}

	// direction to apply to the field when the menu has no direction chosen
	public function setDefaultDirection(string $direction): static
	{
		if (!in_array($direction, [static::SORT_ASC, static::SORT_DESC], true))
		{
			return $this;
		}

		$this->params['defaultDirection'] = $direction;
		return $this;
	}

	public function isSub(): bool
	{
		return ($this->params['type'] ?? null) === MenuItem::TYPE_SUB;
	}

	// sub items share the params of their owner, and the js side reads that list shape
	// as the mark of the custom sort item
	public function addItem(self $item): static
	{
		$this->items->add($item);
		$this->params[] = $item->toArray();
		return $this;
	}

	public function toArray(): array
	{
		$data = [
			'tabId' => $this->getTabId(),
			'html' => $this->getHtml(),
		];

		if ($this->delimiter)
		{
			$data['delimiter'] = true;
		}

		if(!empty($this->getClassName()))
		{
			$data['className'] = $this->getClassName();
		}

		if(!empty($this->getOnClick()))
		{
			$data['onclick'] = $this->getOnClick();
		}

		if (!$this->isFocusable())
		{
			$data['focusable'] = false;
		}

		if (!empty($this->getParams()))
		{
			$data['params'] = $this->getParams();
		}

		return $data;
	}

	private function init(): void
	{
		Loc::loadMessages(__FILE__);
		$this->items = new ItemCollection();
	}
}