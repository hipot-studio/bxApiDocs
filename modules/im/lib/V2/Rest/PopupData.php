<?php

namespace Bitrix\Im\V2\Rest;

class PopupData
{
	protected const DEFAULT_MAX_LEVEL = 1;

	/**
	 * @var PopupDataItem[]
	 */
	protected array $popupItems = [];
	protected array $excludedList = [];
	protected int $maxLevel = self::DEFAULT_MAX_LEVEL;
	protected array $option = [];

	/**
	 * @param PopupDataItem[] $popupDataItems
	 * @param string[]|PopupDataItem[] $excludedList
	 */
	public function __construct(array $popupDataItems, array $excludedList = [])
	{
		foreach ($popupDataItems as $popupDataItem)
		{
			$this->add($popupDataItem);
		}
		$this->excludedList = $excludedList;
		$this->filterItems($excludedList);
	}

	public function merge(self $popupData): self
	{
		foreach ($popupData->popupItems as $popupItem)
		{
			$this->mergeItem($popupItem);
		}

		return $this;
	}

	public function add(PopupDataItem $item): self
	{
		$this->mergeItem($item);

		return $this;
	}

	public function mergeFromEntity(RestConvertible $entity, array $excludedList = []): self
	{
		if ($entity instanceof PopupDataAggregatable)
		{
			return $this->merge($entity->getPopupData($excludedList));
		}

		return $this;
	}

	/**
	 * TPL-01: hands the format options to the items of this level. Items that read the options
	 * while filling themselves need them before the fill pass, not at serialization time.
	 */
	public function applyOptions(array $option): void
	{
		$this->option = $option;

		foreach ($this->popupItems as $item)
		{
			if ($item instanceof PopupDataOptionAware)
			{
				$item->applyOptions($option);
			}
		}
	}

	public function toRestFormat(array $options = []): array
	{
		$result = [];
		$this->maxLevel = $options['POPUP_MAX_LEVEL'] ?? static::DEFAULT_MAX_LEVEL;
		// Consumers that build PopupData without RestAdapter get the same guarantee: applyOptions
		// is idempotent, so the adapter calling it earlier costs nothing.
		$this->applyOptions($options);
		$this->fillNextLevel();

		foreach ($this->popupItems as $item)
		{
			$result[$item::getRestEntityName()] = $item->toRestFormat($options);
		}

		return $result;
	}

	protected function fillNextLevel(int $level = 1): void
	{
		if ($level > $this->maxLevel)
		{
			return;
		}

		$innerPopupData = new static([], $this->excludedList);
		$innerPopupData->maxLevel = $this->maxLevel;

		foreach ($this->popupItems as $item)
		{
			if ($item instanceof PopupDataAggregatable)
			{
				$innerPopupData->merge($item->getPopupData($this->excludedList));
			}
		}

		// TPL-01: the nested level gets the options before its own fill pass, otherwise deeper
		// levels would read them after their selects, just like the top level did before.
		$innerPopupData->applyOptions($this->option);
		$innerPopupData->fillNextLevel($level + 1);
		$this->merge($innerPopupData);
	}

	private function mergeItem(PopupDataItem $popupItem): void
	{
		if (!isset($this->popupItems[$popupItem::getRestEntityName()]))
		{
			$this->popupItems[$popupItem::getRestEntityName()] = $popupItem;
			$this->filterItems($this->excludedList);
		}
		else
		{
			$this->popupItems[$popupItem::getRestEntityName()]->merge($popupItem);
		}
	}

	/**
	 * @param string[]|PopupDataItem[] $excludedList
	 * @return void
	 */
	private function filterItems(array $excludedList): void
	{
		foreach ($excludedList as $excludedItem)
		{
			unset($this->popupItems[$excludedItem::getRestEntityName()]);
		}
	}
}
