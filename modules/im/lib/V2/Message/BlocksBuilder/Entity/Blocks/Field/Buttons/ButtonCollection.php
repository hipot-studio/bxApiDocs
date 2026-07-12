<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons;

use Bitrix\Im\V2\Registry;

/**
 * @implements \IteratorAggregate<int,Button>
 * @method Button offsetGet($key)
 */
class ButtonCollection extends Registry implements \JsonSerializable
{
	public static function create(array $buttonsData): self
	{
		$collection = new self();

		foreach ($buttonsData as $linePosition => $buttonLine)
		{
			foreach ($buttonLine as $buttonData)
			{
				$buttonData['linePosition'] = $linePosition;
				$button = Button::create($buttonData);
				if ($button !== null)
				{
					$collection->append($button);
				}
			}
		}

		return $collection;
	}

	public function jsonSerialize(): ?array
	{
		$result = [];
		foreach ($this as $button)
		{
			$result[$button->getLinePosition()][] = $button->jsonSerialize();
		}

		if (empty($result))
		{
			return null;
		}

		return $result;
	}

	public function toArray(): ?array
	{
		$result = [];
		foreach ($this as $button)
		{
			$result[$button->getLinePosition()][] = $button->toArray();
		}

		if (empty($result))
		{
			return null;
		}

		return $result;
	}
}
