<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

final class StepConfig
{
	public const ID_PATTERN = '/^A\d{4}_\d{4}_\d{4}_\d{4}$/';

	public function __construct(
		public readonly string $type,
		public readonly array $props = [],
		public readonly ?string $id = null,
		public readonly bool $isCondition = false,
		/** @var ConditionConfig[] */
		public readonly array $conditions = [],
		/** @var StepConfig[] */
		public readonly array $trueBranch = [],
		/** @var StepConfig[] */
		public readonly array $falseBranch = [],
		public readonly bool $isComposite = false,
		/** @var StepConfig[] */
		public readonly array $childSteps = [],
	) {}

	public static function fromMixed(mixed $data): self
	{
		if (is_string($data))
		{
			return new self(type: $data);
		}

		if (!is_array($data) || empty($data))
		{
			throw new \InvalidArgumentException('Step definition must be a non-empty string or array');
		}

		$type = array_key_first($data);
		if (!is_string($type))
		{
			throw new \InvalidArgumentException('Step array must have a string key as activity type');
		}

		$config = is_array($data[$type]) ? $data[$type] : [];
		$id = self::validateId($config['_id'] ?? null);

		// Condition: has 'conditions' + 'true'/'false' branches
		if (isset($config['conditions']))
		{
			return new self(
				type: $type,
				id: $id,
				isCondition: true,
				conditions: array_map([ConditionConfig::class, 'fromArray'], $config['conditions']),
				trueBranch: array_map([self::class, 'fromMixed'], $config['true'] ?? []),
				falseBranch: array_map([self::class, 'fromMixed'], $config['false'] ?? []),
			);
		}

		// Composite: has 'steps' (ForEach, etc.)
		if (isset($config['steps']))
		{
			$childSteps = array_map([self::class, 'fromMixed'], $config['steps']);
			$props = array_diff_key($config, ['_id' => true, 'steps' => true]);

			if (isset($props['Variable']) && is_string($props['Variable']))
			{
				if (preg_match('/^\{=(\w+):(\w+)}$/', $props['Variable'], $matches))
				{
					$props['Object'] = $matches[1];
					$props['Variable'] = $matches[2];
				}
			}

			return new self(
				type: $type,
				props: $props,
				id: $id,
				isComposite: true,
				childSteps: $childSteps,
			);
		}

		// Simple activity
		$props = array_diff_key($config, ['_id' => true]);

		return new self(type: $type, props: $props, id: $id);
	}

	private static function validateId(?string $id): ?string
	{
		if ($id === null)
		{
			return null;
		}

		if (!preg_match(self::ID_PATTERN, $id))
		{
			throw new \InvalidArgumentException("Invalid activity ID format: '{$id}'. Expected: A0000_0000_0000_0000");
		}

		return $id;
	}
}
