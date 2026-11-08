<?php

namespace Bitrix\Crm\Integration\Landing\Vibe;

final class VibeReference
{
	public function __construct(
		public readonly int $id,
		public readonly string $embedId,
		public readonly bool $isCreated = true,
	)
	{
	}
}
