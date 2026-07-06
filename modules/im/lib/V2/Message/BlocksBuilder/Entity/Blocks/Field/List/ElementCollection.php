<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\List;

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

	public function toArray(): array
	{
		$result = [];
		foreach ($this as $element)
		{
			$result[] = $element->toArray();
		}

		return $result;
	}

	public static function create($elementsData, bool $isOrdered): self
	{
		$collection = new self();

		foreach ($elementsData as $elementData)
		{
			$element = Element::create($elementData, $isOrdered);
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
