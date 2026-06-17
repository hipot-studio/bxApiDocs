<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\AgentConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\StepConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;

final class ConfigValidator
{
	private array $warnings = [];

	public function __construct(
		private readonly ActivityRegistry $registry,
	) {}

	/**
	 * Does not block generation — returns warnings only.
	 *
	 * @return string[]
	 */
	public function validate(AgentConfig $config): array
	{
		$this->warnings = [];

		foreach ($config->flows as $flow)
		{
			$this->validateTrigger($flow->trigger, $flow->triggerProps, $flow->name);

			foreach ($flow->steps as $step)
			{
				$this->validateStep($step, $flow->name);
			}
		}

		return $this->warnings;
	}

	private function validateTrigger(string $triggerType, array $props, string $flowName): void
	{
		$filteredProps = array_diff_key($props, ['_id' => true]);
		$this->validateActivityProperties($triggerType, $filteredProps, "{$flowName} (trigger)");
	}

	private function validateStep(StepConfig $step, string $flowName): void
	{
		if ($step->isCondition)
		{
			foreach ($step->trueBranch as $child)
			{
				$this->validateStep($child, $flowName);
			}
			foreach ($step->falseBranch as $child)
			{
				$this->validateStep($child, $flowName);
			}

			return;
		}

		if ($step->isComposite)
		{
			$this->validateActivityProperties($step->type, $step->props, $flowName);
			foreach ($step->childSteps as $child)
			{
				$this->validateStep($child, $flowName);
			}

			return;
		}

		if (!empty($step->props))
		{
			$this->validateActivityProperties($step->type, $step->props, $flowName);
		}
	}

	private function validateActivityProperties(string $activityType, array $configProps, string $context): void
	{
		$knownFields = $this->registry->getPropertyNames($activityType);
		if ($knownFields === null)
		{
			return;
		}

		$isConfigurable = $this->registry->isConfigurable($activityType);

		foreach ($configProps as $key => $value)
		{
			if (!in_array($key, $knownFields, true))
			{
				$suffix = $isConfigurable ? ' (activity has dynamic properties — may be valid)' : '';
				$this->warnings[] = "[{$context}] {$activityType}: unknown property \"{$key}\"{$suffix}";
			}
		}
	}
}
