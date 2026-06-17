<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

final class LayoutEngine
{
	private const X_STEP = 400;
	private const Y_FLOW_STEP = 500;
	private const BRANCH_ROW_OFFSET = 200;
	private const START_X = 100;
	private const START_Y = 200;

	private int $currentFlowIndex = 0;
	private ?int $yOverride = null;
	private array $yStack = [];

	public function nextFlow(): void
	{
		$this->currentFlowIndex++;
	}

	public function getFlowBaseY(): int
	{
		return self::START_Y + ($this->currentFlowIndex * self::Y_FLOW_STEP);
	}

	public function calculatePosition(int $stepIndex): Position
	{
		$x = self::START_X + ($stepIndex * self::X_STEP);
		$y = $this->yOverride ?? $this->getFlowBaseY();

		return new Position($x, $y);
	}

	public function shiftRow(): void
	{
		$this->yStack[] = $this->yOverride;
		$this->yOverride = ($this->yOverride ?? $this->getFlowBaseY()) + self::BRANCH_ROW_OFFSET;
	}

	public function resetRow(): void
	{
		$this->yOverride = array_pop($this->yStack);
	}
}
