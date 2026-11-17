<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Dto;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Type\DateTime;

class DefaultDeadlineResponse implements Arrayable
{
	public function __construct(
		private readonly ?DateTime $defaultDeadline,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'defaultDeadlineTs' => $this->defaultDeadline?->getTimestamp(),
			'isDefaultDeadlineEnabled' => $this->defaultDeadline !== null,
		];
	}
}
