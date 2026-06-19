<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\List;

use Bitrix\Im\V2\Registry;

/**
 * @implements \IteratorAggregate<int,Element>
 * @method Element offsetGet($key)
 */
class ElementCollection extends Registry implements \JsonSerializable
{
	public function jsonSerialize(): array
	{
		$result = [];
		foreach ($this as $element)
		{
			$result[] = $element->jsonSerialize();
		}

		return $result;
	}

	public static function create($elementsData): self
	{
		$collection = new self();

		foreach ($elementsData as $elementData)
		{
			$element = Element::create($elementData);
			$collection->append($element);
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
