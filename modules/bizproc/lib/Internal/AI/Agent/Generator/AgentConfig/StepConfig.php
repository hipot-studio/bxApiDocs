<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

final class StepConfig
{
	/** Body of the activity-ID regex without delimiters or anchors — share it via {@see self::ID_PATTERN_BODY}. */
	public const ID_PATTERN_BODY = 'A\d{4,}_\d{4,}_\d{4,}_\d{4,}';
	public const ID_PATTERN = '/^' . self::ID_PATTERN_BODY . '$/';

	public function __construct(
		public readonly string $type,
		public readonly array $props = [],
		public readonly ?string $id = null,
		public readonly ?string $innerId = null,
		public readonly ?string $innerType = null,
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
		public readonly bool $isBranches = false,
		/** @var array<string, StepConfig[]> port id ("o0", "o1", ...) → branch steps */
		public readonly array $branches = [],
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
			$rawConditions = $config['conditions'];
			if (!is_array($rawConditions))
			{
				throw new \InvalidArgumentException("Step '$type': 'conditions' must be an array");
			}
			$trueRaw = $config['true'] ?? [];
			$falseRaw = $config['false'] ?? [];
			if (!is_array($trueRaw) || !is_array($falseRaw))
			{
				throw new \InvalidArgumentException("Step '$type': 'true'/'false' branches must be arrays");
			}

			return new self(
				type: $type,
				props: array_diff_key($config, [
					'_id' => true,
					'conditions' => true,
					'true' => true,
					'false' => true,
				]),
				id: $id,
				isCondition: true,
				conditions: array_map([ConditionConfig::class, 'fromArray'], $rawConditions),
				trueBranch: array_map([self::class, 'fromMixed'], $trueRaw),
				falseBranch: array_map([self::class, 'fromMixed'], $falseRaw),
			);
		}

		if (array_key_exists('branches', $config))
		{
			if (!is_array($config['branches']))
			{
				throw new \InvalidArgumentException("Step '$type': 'branches' must be an array");
			}
			$branches = [];
			foreach ($config['branches'] as $port => $branchSteps)
			{
				if (!is_string($port) || !preg_match('/^o\d+$/', $port))
				{
					throw new \InvalidArgumentException("Invalid branch port '$port' in step '$type'. Expected format: o0, o1, ...");
				}
				if (!is_array($branchSteps))
				{
					throw new \InvalidArgumentException("Step '$type': branch '$port' must be an array of steps");
				}
				$branches[$port] = array_map([self::class, 'fromMixed'], $branchSteps);
			}
			$props = array_diff_key($config, ['_id' => true, 'branches' => true]);

			return new self(
				type: $type,
				props: $props,
				id: $id,
				isBranches: true,
				branches: $branches,
			);
		}

		// Composite: has 'steps' (ForEach, etc.)
		if (isset($config['steps']))
		{
			$rawSteps = $config['steps'];
			if (!is_array($rawSteps))
			{
				throw new \InvalidArgumentException("Step '$type': 'steps' must be an array");
			}
			$childSteps = array_map([self::class, 'fromMixed'], $rawSteps);
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

		// Simple activity or complex wrapper — detected by type in TemplateBuilder
		$innerId = isset($config['_inner_id']) ? self::validateId($config['_inner_id']) : null;
		$innerType = isset($config['_inner_type']) ? (string)$config['_inner_type'] : null;
		$props = array_diff_key($config, ['_id' => true, '_inner_id' => true, '_inner_type' => true]);

		return new self(type: $type, props: $props, id: $id, innerId: $innerId, innerType: $innerType);
	}

	private static function validateId(mixed $id): ?string
	{
		if ($id === null)
		{
			return null;
		}

		if (!is_string($id))
		{
			throw new \InvalidArgumentException("Activity _id must be a string, got " . get_debug_type($id));
		}

		if (!preg_match(self::ID_PATTERN, $id))
		{
			throw new \InvalidArgumentException("Invalid activity ID format: '$id'. Expected: A followed by four numeric segments separated by '_' (each segment >= 4 digits)");
		}

		return $id;
	}
}
