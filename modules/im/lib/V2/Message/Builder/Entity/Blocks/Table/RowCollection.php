<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\Table;

use Bitrix\Im\V2\Registry;

/**
 * @implements \IteratorAggregate<int,Row>
 * @method Row offsetGet($key)
 */
class RowCollection extends Registry implements \JsonSerializable
{
	public function jsonSerialize(): array
	{
		$result = [];
		foreach ($this as $row)
		{
			$result[] = $row->jsonSerialize();
		}

		return $result;
	}

	public static function create($rowsData): self
	{
		$collection = new self();

		foreach ($rowsData as $rowData)
		{
			$row = Row::create($rowData);
			$collection->append($row);
		}

		return $collection;
	}

	public function getPayloadText(): string
	{
		$result = '';
		foreach ($this as $element)
		{
			$text = $element->getPayloadText();
			if (!empty($text))
			{
				$result .= $text . PHP_EOL;
			}
		}

		return trim($result);
	}
}
