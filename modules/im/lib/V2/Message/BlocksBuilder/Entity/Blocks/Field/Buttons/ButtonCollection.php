<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons;

use Bitrix\Im\V2\Registry;
use Bitrix\Main\Security\Random;

/**
 * @implements \IteratorAggregate<int,Button>
 * @method Button offsetGet($key)
 */
class ButtonCollection extends Registry implements \JsonSerializable
{
	public static function create(array $buttonsData): self
	{
		$collection = new self();
		$seenIds = [];

		foreach ($buttonsData as $linePosition => $buttonLine)
		{
			foreach ($buttonLine as $buttonData)
			{
				$buttonData['linePosition'] = $linePosition;

				$id = (string)($buttonData['id'] ?? '');
				if ($id === '' || isset($seenIds[$id]))
				{
					$id = self::generateUniqueId($seenIds);
				}
				$buttonData['id'] = $id;
				$seenIds[$id] = true;

				$button = Button::create($buttonData);
				if ($button !== null)
				{
					$collection->append($button);
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
