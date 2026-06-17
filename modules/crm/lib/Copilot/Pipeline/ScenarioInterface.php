<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Crm\Integration\AI\Operation\AbstractOperation;

interface ScenarioInterface
{
	/**
	 * Unique scenario identifier (e.g. 'fill_fields')
	 *
	 * @return string
	 */
	public function getId(): string;

	/**
	 * Ordered list of operation class names (class-string<AbstractOperation>[])
	 *
	 * @return class-string<AbstractOperation>[]
	 */
	public function getSteps(): array;

	/**
	 * Whether the scenario is enabled in global settings
	 *
	 * @return bool
	 */
	public function isEnabled(): bool;

	/**
	 * Resolve actual step list, accounting for skip-transcription mode
	 *
	 * @param string|null $activityProvider
	 * @return array
	 */
	public function resolveSteps(?string $activityProvider): array;

	/**
	 * Slider code for "scenario disabled" error
	 *
	 * @return string|null
	 */
	public function getDisabledSliderCode(): ?string;
}
