<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe\Provider;

/**
 * Context information required by a Vibe provider.
 * Kept intentionally minimal to allow using the same provider for multiple embed points.
 */
final readonly class VibeContextDto
{
	public function __construct(
		private string $moduleId,
		private string $embedId,
	)
	{
	}

	public function getModuleId(): string
	{
		return $this->moduleId;
	}

	public function getEmbedId(): string
	{
		return $this->embedId;
	}
}

