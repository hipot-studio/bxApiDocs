<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\CrmForm;

use Bitrix\Main\Type\Contract\Arrayable;

class ResourceAutoSelectionSearchResult implements Arrayable
{
	public function __construct(
		private readonly int|null $resourceId = null,
		private readonly string|null $date = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'resourceId' => $this->resourceId,
			'date' => $this->date,
		];
	}
}
