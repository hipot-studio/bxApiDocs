<?php

namespace Bitrix\Main\Grid\Column\Editable;

use Bitrix\Main\Grid\Editor\Types;

class ListConfig extends Config
{
	private array $items;
	private ?string $emptyValueCaption;

	/**
	 * @param string[] $items in format `[value => name]`
	 * @param string|null $type
	 */
	public function __construct(
		string $name,
		array $items,
		string $type = Types::DROPDOWN,
		?string $emptyValueCaption = null
	)
	{
		parent::__construct($name, $type);

		$this->items = $items;
		$this->emptyValueCaption = $emptyValueCaption;
	}

	/**
	 * Items as dropdown.
	 *
	 * @return array
	 */
	private function getItemsAsDropdown(): array
	{
		$result = [];

		foreach ($this->items as $value => $name)
		{
			$result[] = [
				'VALUE' => $value,
				'NAME' => $name,
			];
		}

		return $result;
	}

	/**
	 * @inheritDoc
	 */
	public function toArray(): array
	{
		$result = parent::toArray();

		$result['DATA'] = [
			'ITEMS' => $this->getItemsAsDropdown(),
		];

		if (isset($this->emptyValueCaption))
		{
			$result['DATA']['EMPTY_VALUE_CAPTION'] = $this->emptyValueCaption;
		}

		return $result;
	}
}
