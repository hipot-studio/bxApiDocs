<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Flow\Integration\AI\Provider;

use Bitrix\Main\Web\Json;

class CollectedData
{
	public function __construct(
		private readonly int $flowId,
		private readonly array $data = [],
		private readonly ?CollectedDataStatus $status = null,
	)
	{
	}

	public function getPayload(): string
	{
		return (string)Json::encode($this->data);
	}

	public function getData(): array
	{
		return $this->data;
	}

	public function getStatus(): ?CollectedDataStatus
	{
		return $this->status;
	}

	public function isEmpty(): bool
	{
		return empty($this->data);
	}
}