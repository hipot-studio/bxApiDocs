<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer;

use Bitrix\Im\V2\Registry;
use Bitrix\Main\Security\Random;

/**
 * @implements \IteratorAggregate<int,FooterElement>
 * @method FooterElement offsetGet($key)
 */
class FooterElementCollection extends Registry
{
	public static function create(array $footerData): self
	{
		$collection = new self();
		$seenIds = [];

		foreach ($footerData as $linePosition => $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			foreach ($row as $elementData)
			{
				$elementData['linePosition'] = $linePosition;

				$id = (string)($elementData['id'] ?? '');
				if ($id === '' || isset($seenIds[$id]))
				{
					$id = self::generateUniqueId($seenIds);
				}
				$elementData['id'] = $id;
				$seenIds[$id] = true;

				$element = FooterElement::create($elementData);
				if ($element !== null)
				{
					$collection->append($element);
				}
			}
		}

		return $collection;
	}

	private static function generateUniqueId(array $seenIds): string
	{
		do
		{
			$id = Random::getString(13, true);
		}
		while (isset($seenIds[$id]));

		return $id;
	}

	public function getById(string $id): ?FooterElement
	{
		foreach ($this as $element)
		{
			if ($element->getId() === $id)
			{
				return $element;
			}
		}

		return null;
	}

	public function toArray(): ?array
	{
		$result = [];
		foreach ($this as $element)
		{
			$result[$element->getLinePosition()][] = $element->toArray();
		}

		if (empty($result))
		{
			return null;
		}

		return array_values($result);
	}
}
