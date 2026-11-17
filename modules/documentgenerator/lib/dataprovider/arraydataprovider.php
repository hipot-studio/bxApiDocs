<?php

namespace Bitrix\DocumentGenerator\DataProvider;

use Bitrix\DocumentGenerator\DataProvider;
use Bitrix\DocumentGenerator\DataProviderManager;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Localization\Loc;

class ArrayDataProvider extends DataProvider implements \Iterator, \Countable
{
	public const INDEX_PLACEHOLDER = 'INDEX';
	public const NUMBER_PLACEHOLDER = 'NUMBER';

	protected $itemProvider;
	protected $itemKey;
	protected $itemTitle;
	private bool $itemsModified = false;
	private ?array $itemIdPositions = null;
	private ?DataProviderManager $itemIdManager = null;
	private ?DataProvider $iterationItem = null;
	private ?DataProvider $indexedItem = null;

	/** @internal */
	public function enableItemIds(DataProviderManager $manager): void
	{
		$this->itemIdPositions = array_fill_keys(array_keys($this->data), true);
		$this->itemIdManager = $manager;
	}

	/** @internal */
	public function getPinnedItems(): array
	{
		return array_filter([$this->iterationItem, $this->indexedItem]);
	}

	private function materializeItem($index)
	{
		if (isset($this->itemIdPositions[$index]))
		{
			$item = $this->itemIdManager->getCollectionItem($this, $this->data[$index]);
			if (!$this->itemIdManager->isCollectionItemRetained($item))
			{
				$this->data[$index] = $item;
				unset($this->itemIdPositions[$index]);
			}

			return $item;
		}

		return $this->data[$index] ?? null;
	}

	public function getSource()
	{
		if ($this->itemIdPositions === null)
		{
			return parent::getSource();
		}
		$result = [];
		foreach ($this->source as $id)
		{
			$result[] = $this->itemIdManager->getCollectionItem($this, $id);
		}

		return $result;
	}

	/**
	 * @param array $source
	 * @param array $options
	 * @throws ArgumentTypeException
	 */
	public function __construct($source, array $options = [])
	{
		parent::__construct($source, $options);
		if(is_array($source))
		{
			$this->data = $source;
		}
		if(isset($options['ITEM_PROVIDER'], $options['ITEM_NAME']))
		{
			$itemTitle = '';
			if($options['ITEM_TITLE'])
			{
				$itemTitle = $options['ITEM_TITLE'];
			}
			$this->setItemProvider($options['ITEM_NAME'], $options['ITEM_PROVIDER'], $itemTitle);
		}
	}

	/**
	 * @param string $itemKey
	 * @param string|DataProvider $itemProvider
	 * @param string $itemTitle
	 * @throws ArgumentTypeException
	 */
	protected function setItemProvider($itemKey, $itemProvider, $itemTitle = '')
	{
		if(DataProviderManager::getInstance()->checkProviderName($itemProvider))
		{
			if(is_object($itemProvider))
			{
				$this->itemProvider = get_class($itemProvider);
			}
			else
			{
				$this->itemProvider = $itemProvider;
			}
			$this->itemKey = $itemKey;
			$this->itemTitle = $itemTitle;
		}
		else
		{
			throw new ArgumentTypeException('itemProvider');
		}
	}

	public function hasAccess($userId)
	{
		// has access to this provider if the user has access to least one of its items
		if (is_array($this->data))
		{
			foreach (array_keys($this->data) as $index)
			{
				$item = $this->materializeItem($index);
				if ($item instanceof DataProvider)
				{
					$hasAccess = DataProviderManager::getInstance()->checkDataProviderAccess($item, $userId);
					if ($hasAccess)
					{
						return true;
					}
				}
			}
		}

		return parent::hasAccess($userId);
	}

	/**
	 * @return array
	 */
	public function getFields()
	{
		$fields = [];
		if($this->itemProvider && $this->itemKey)
		{
			$fields[$this->itemKey] = [
				'PROVIDER' => $this->itemProvider,
			];
			if($this->itemTitle)
			{
				$fields[$this->itemKey]['TITLE'] = $this->itemTitle;
			}
			if(isset($this->options['ITEM_OPTIONS']) && is_array($this->options['ITEM_OPTIONS']))
			{
				$fields[$this->itemKey]['OPTIONS'] = $this->options['ITEM_OPTIONS'];
			}
		}
		$fields[static::NUMBER_PLACEHOLDER] = [
			'TITLE' => Loc::getMessage('DOCGEN_DATAPROVIDER_ARRAY_NUMBER_TITLE'),
		];
		$fields[static::INDEX_PLACEHOLDER] = [
			'TITLE' => Loc::getMessage('DOCGEN_DATAPROVIDER_ARRAY_INDEX_TITLE'),
		];

		return $fields;
	}

	/**
	 * Returns value by its name.
	 *
	 * @param string $name
	 * @return mixed
	 */
	public function getValue($name)
	{
		$value = null;

		if(is_array($this->data))
		{
			if($name === static::NUMBER_PLACEHOLDER)
			{
				$value = count($this->data);
			}
			elseif($name === static::INDEX_PLACEHOLDER)
			{
				$value = ($this->key() + 1);
			}
			elseif(isset($this->data[$name]))
			{
				$value = $this->materializeItem($name);
				if ($this->itemIdPositions !== null)
				{
					$this->indexedItem = $value instanceof DataProvider ? $value : null;
				}
			}
		}

		return $value;
	}

	public function getItemByIndex(int $index)
	{
		return $this->getValue($index);
	}

	public function replaceItem(int $index, $item): ArrayDataProvider
	{
		$oldItem = $this->getItemByIndex($index);
		if(!$oldItem)
		{
			throw new \OutOfRangeException('There is no item with index ' . $index);
		}
		$this->data[$index] = $item;
		unset($this->itemIdPositions[$index]);
		$this->itemsModified = true;

		return $this;
	}

	public function addItem($item): int
	{
		$this->data[] = $item;
		$this->itemsModified = true;

		return count($this->data);
	}

	public function deleteItemByIndex(int $index): ArrayDataProvider
	{
		unset($this->data[$index]);
		if ($this->itemIdPositions !== null)
		{
			$positions = [];
			foreach (array_keys($this->data) as $newIndex => $oldIndex)
			{
				if (isset($this->itemIdPositions[$oldIndex]))
				{
					$positions[$newIndex] = true;
				}
			}
			$this->itemIdPositions = $positions;
		}
		$this->data = array_values($this->data);
		$this->itemsModified = true;

		return $this;
	}

	public function getModifiedItems(): ?array
	{
		if (!$this->itemsModified)
		{
			return null;
		}
		$result = [];
		foreach (array_keys($this->data) as $index)
		{
			$result[$index] = $this->materializeItem($index);
		}

		return $result;
	}

	/**
	 * @return string
	 */
	public function getItemKey(): ?string
	{
		return $this->itemKey;
	}

	public function current(): mixed
	{
		if(is_array($this->data))
		{
			if ($this->itemIdPositions !== null && $this->key() !== null)
			{
				$item = $this->materializeItem($this->key());
				$this->iterationItem = $item instanceof DataProvider ? $item : null;

				return $item;
			}

			return current($this->data);
		}

		return false;
	}

	public function next(): void
	{
		if(is_array($this->data))
		{
			next($this->data);
			$this->iterationItem = null;
			$this->itemIdManager?->onCollectionStep();
		}
	}

	public function key(): string|int|null
	{
		if(is_array($this->data))
		{
			return key($this->data);
		}

		return null;
	}

	public function valid(): bool
	{
		return $this->key() !== null;
	}

	public function rewind(): void
	{
		if(is_array($this->data))
		{
			reset($this->data);
			$this->iterationItem = null;
		}
	}

	public function count(): int
	{
		if(is_array($this->data))
		{
			return count($this->data);
		}

		return 0;
	}
}
