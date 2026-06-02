<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Integration\AiAssistant\Service\Dto;

use Bitrix\Socialnetwork\Item\Workgroup\Type;

class SearchGroupsDto
{
	private function __construct(
		public readonly ?string $title = null,
		public readonly ?string $description = null,
		public readonly ?Type $type = null,
		public readonly ?int $ownerId = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			title: isset($props['title']) && is_string($props['title']) ? $props['title'] : null,
			description: isset($props['description']) && is_string($props['description']) ? $props['description'] : null,
			type: isset($props['type']) && is_string($props['type']) ? Type::tryFrom($props['type']) : null,
			ownerId: isset($props['ownerId']) && is_numeric($props['ownerId']) ? (int)$props['ownerId'] : null,
		);
	}
}
