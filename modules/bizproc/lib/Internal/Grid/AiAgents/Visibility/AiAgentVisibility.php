<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Visibility;

final readonly class AiAgentVisibility
{
	/**
	 * @param list<string> $exceptRegions Regions where the system agent remains visible.
	 */
	private function __construct(public array $exceptRegions)
	{
	}

	public static function hiddenEverywhere(): self
	{
		return new self([]);
	}

	public static function hiddenExceptRegions(string ...$regions): self
	{
		return new self(array_values($regions));
	}

	public function isHiddenFor(string $region): bool
	{
		return !in_array($region, $this->exceptRegions, true);
	}
}
