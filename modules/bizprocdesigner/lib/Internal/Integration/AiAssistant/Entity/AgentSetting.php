<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

final class AgentSetting implements Arrayable
{
	public function __construct(
		public readonly string $name,
		public readonly string|array $value,
	) {}

	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'value' => $this->value,
		];
	}
}