<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

final class AgentConnection implements Arrayable
{
	public function __construct(
		public readonly string $destinationBlockId,
		public readonly string $sourceBlockId,
	) {}

	public function toArray(): array
	{
		return [
			'destinationBlockId' => $this->destinationBlockId,
			'sourceBlockId' => $this->sourceBlockId,
		];
	}
}