<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Collection;

use Bitrix\Booking\Internals\Exception\InvalidArgumentException;
use Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Collection;
use Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Item;

class AvailableTimeSlotCollection extends Collection
{
	public function add(Item $item): self
	{
		if (!$item instanceof Item\AvailableTimeSlot)
		{
			throw new InvalidArgumentException();
		}

		$this->items[] = $item;

		return $this;
	}
}
