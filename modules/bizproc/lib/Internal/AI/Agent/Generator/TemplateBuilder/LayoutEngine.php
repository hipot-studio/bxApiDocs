<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

final class LayoutEngine
{
	private const X_STEP = 400;
	private const Y_FLOW_STEP = 500;
	private const Y_FLOW_PADDING = 200;
	private const BRANCH_ROW_OFFSET = 200;
	private const COMPOSITE_LOOPBACK_RESERVE = 200;
	private const START_X = 100;
	private const START_Y = 200;

	private int $currentFlowBaseY = self::START_Y;
	private int $maxYInCurrentFlow = self::START_Y;
	private ?int $yOverride = null;
	private array $yStack = [];

	public function nextFlow(): void
	{
		$nextBaseY = max(
			$this->currentFlowBaseY + self::Y_FLOW_STEP,
			$this->maxYInCurrentFlow + self::Y_FLOW_PADDING,
		);

		$this->currentFlowBaseY = $nextBaseY;
		$this->maxYInCurrentFlow = $nextBaseY;
	}

	public function getFlowBaseY(): int
	{
		return $this->currentFlowBaseY;
	}

	public function calculatePosition(int $stepIndex): Position
	{
		$x = self::START_X + ($stepIndex * self::X_STEP);
		$y = $this->yOverride ?? $this->currentFlowBaseY;

		if ($y > $this->maxYInCurrentFlow)
		{
			$this->maxYInCurrentFlow = $y;
		}

		return new Position($x, $y);
	}

	public function shiftRow(): void
	{
		$this->yStack[] = $this->yOverride;

		$parentY = $this->yOverride ?? $this->currentFlowBaseY;
		$this->yOverride = max(
			$parentY + self::BRANCH_ROW_OFFSET,
			$this->maxYInCurrentFlow + self::BRANCH_ROW_OFFSET,
		);
	}

	public function resetRow(): void
	{
		$this->yOverride = array_pop($this->yStack);
	}

	public function reserveCompositeLoopback(): void
	{
		$reserved = $this->maxYInCurrentFlow + self::COMPOSITE_LOOPBACK_RESERVE;
		if ($reserved > $this->maxYInCurrentFlow)
		{
			$this->maxYInCurrentFlow = $reserved;
		}
	}
}
