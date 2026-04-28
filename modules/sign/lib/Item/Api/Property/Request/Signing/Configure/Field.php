<?php

namespace Bitrix\Sign\Item\Api\Property\Request\Signing\Configure;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;

class Field implements Contract\Item
{
	public int $party;
	public string $type;
	public string $name;
	public ?string $label = null;
	public ?string $hint = null;
	public ?string $placeholder = null;
	public ?bool $required = null;
	public ?string $alias = null;

	public function __construct(
		int $party,
		string $type,
		string $name,
		?string $label = null,
		public ?Field\ItemCollection $items = null,
		public ?SubFieldCollection $subfields = null,
		?string $alias = null,
	)
	{
		$this->party = $party;
		$this->type = $type;
		$this->name = $name;
		$this->label = $label;
		$this->alias = $alias;
	}

	public static function createFromFieldItem(Item\Field $field): static
	{
		$items = null;
		if ($field->items !== null)
		{
			$items = new Item\Api\Property\Request\Signing\Configure\Field\ItemCollection();
			foreach ($field->items as $item)
			{
				$items->addItem(
					Item\Api\Property\Request\Signing\Configure\Field\Item::createFromFieldItem($item),
				);
			}
		}

		$subfields = null;
		if ($field->subfields !== null)
		{
			$subfields = new SubFieldCollection();
			foreach ($field->subfields as $subField)
			{
				$subfields->addItem(
					SubField::createFromFieldItem($subField),
				);
			}
		}

		$instance = new static(
			$field->party,
			$field->type,
			$field->name,
			$field->label,
			$items,
			$subfields,
			$field->alias,
		);
		$instance->required = $field->required;

		return $instance;
	}
}
