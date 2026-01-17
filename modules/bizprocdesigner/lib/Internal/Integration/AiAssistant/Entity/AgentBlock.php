<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

final class AgentBlock implements Arrayable
{
	public function __construct(
		public readonly string $type,
		public readonly string $title,
		public readonly string $id,
		public readonly AgentSettingCollection $settings,
	) {}

	public function toArray(): array
	{
		return [
			'type' => $this->type,
			'title' => $this->title,
			'id' => $this->id,
			'settings' => $this->settings->toArray(),
		];
	}
}