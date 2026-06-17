<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

final class FlowConfig
{
	public function __construct(
		public readonly string $name,
		public readonly string $trigger,
		public readonly ?string $triggerId = null,
		public readonly array $triggerProps = [],
		/** @var StepConfig[] */
		public readonly array $steps = [],
	) {}

	public static function fromArray(string $name, array $data): self
	{
		if (empty($data['trigger']))
		{
			throw new \InvalidArgumentException("Flow '{$name}' must have a 'trigger'");
		}

		$steps = array_map(
			[StepConfig::class, 'fromMixed'],
			$data['steps'] ?? []
		);

		$triggerProps = $data['trigger_props'] ?? [];
		$triggerId = $triggerProps['_id'] ?? null;
		if ($triggerId !== null && !preg_match(StepConfig::ID_PATTERN, $triggerId))
		{
			throw new \InvalidArgumentException("Invalid trigger ID format: '{$triggerId}'. Expected: A0000_0000_0000_0000");
		}
		unset($triggerProps['_id']);

		return new self(
			name: $name,
			trigger: $data['trigger'],
			triggerId: $triggerId,
			triggerProps: $triggerProps,
			steps: $steps,
		);
	}
}
