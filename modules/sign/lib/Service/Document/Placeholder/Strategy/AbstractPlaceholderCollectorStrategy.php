<?php

namespace Bitrix\Sign\Service\Document\Placeholder\Strategy;

use Bitrix\Sign\Item\Document\Placeholder\PlaceholderCollection;
use Bitrix\Sign\Contract\Document;
use Bitrix\Sign\Item;

abstract class AbstractPlaceholderCollectorStrategy implements Document\PlaceholderCollectorInterface
{
	public function createFromFields(array $fields, int $party): PlaceholderCollection
	{
		$placeholderCollection = new PlaceholderCollection();
		foreach ($fields as $field)
		{
			if (!isset($field['name'], $field['type'], $field['caption']))
			{
				continue;
			}

			$placeholderCode = $this->create($field['name'], $field['type'], $party);
			$placeholderCollection->add(
				new Item\Document\Placeholder\Placeholder(
					$field['caption'],
					$placeholderCode,
				),
			);
		}

		return $placeholderCollection;
	}

	abstract public function create(string $fieldCode, string $fieldType, int $party): string;
}
