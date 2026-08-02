<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Bizproc;

use Bitrix\Main\Type\Contract\Arrayable;

class AiAgentDataDto implements Arrayable
{
	public function __construct(
		public readonly int|null $templateId,
		public readonly string|null $action,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'templateId' => $this->templateId,
			'action' => $this->action,
		];
	}
}
