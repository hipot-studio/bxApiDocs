<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

final class Position
{
	public function __construct(
		public readonly int $x,
		public readonly int $y,
	) {}

	public function toArray(): array
	{
		return ['x' => $this->x, 'y' => $this->y];
	}
}
