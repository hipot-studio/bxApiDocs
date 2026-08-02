<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

final readonly class FlowConfig
{
	public function __construct(
		public string $name,
		public string $trigger,
		public ?string $triggerId = null,
		public array $triggerProps = [],
		/** @var StepConfig[] */
		public array $steps = [],
	) {}

	public static function fromArray(string $name, array $data): self
	{
		$trigger = $data['trigger'] ?? null;
		if (!is_string($trigger) || $trigger === '')
		{
			throw new \InvalidArgumentException("Flow '$name' must have a string 'trigger'");
		}

		$rawSteps = $data['steps'] ?? [];
		if (!is_array($rawSteps))
		{
			throw new \InvalidArgumentException("Flow '$name': 'steps' must be an array");
		}
		$steps = array_map([StepConfig::class, 'fromMixed'], $rawSteps);

		$triggerProps = $data['trigger_props'] ?? [];
		if (!is_array($triggerProps))
		{
			throw new \InvalidArgumentException("Flow '$name': 'trigger_props' must be an array");
		}
		$triggerId = $triggerProps['_id'] ?? null;
		if ($triggerId !== null && !is_string($triggerId))
		{
			throw new \InvalidArgumentException("Flow '$name': trigger _id must be a string");
		}
		if ($triggerId !== null && !preg_match(StepConfig::ID_PATTERN, $triggerId))
		{
			throw new \InvalidArgumentException("Invalid trigger ID format: '$triggerId'. Expected: A followed by four numeric segments separated by '_' (each segment >= 4 digits)");
		}
		unset($triggerProps['_id']);

		return new self(
			name: $name,
			trigger: $trigger,
			triggerId: $triggerId,
			triggerProps: $triggerProps,
			steps: $steps,
		);
	}
}
