<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

final class AgentTemplate implements Arrayable
{
	public function __construct(
		public readonly AgentBlockCollection $blocks,
		public readonly AgentConnectionCollection $connections,
	) {}

	public function toArray(): array
	{
		return [
			'blocks' => $this->blocks->toArray(),
			'connections' => $this->connections->toArray(),
		];
	}
}