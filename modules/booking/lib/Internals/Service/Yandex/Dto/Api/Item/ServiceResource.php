<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Item;

use Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Item;

class ServiceResource extends Item
{
	private string $id;
	private int|null $durationSeconds = null;

	public function __construct(string $id)
	{
		$this->id = $id;
	}

	public function setDurationSeconds(int|null $durationSeconds): self
	{
		$this->durationSeconds = $durationSeconds;

		return $this;
	}

	protected function __toArray(): array
	{
		return [
			'id' => $this->id,
			'durationSeconds' => $this->durationSeconds,
		];
	}
}
