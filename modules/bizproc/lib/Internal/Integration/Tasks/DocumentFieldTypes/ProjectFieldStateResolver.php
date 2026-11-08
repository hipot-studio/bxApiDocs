<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes;

class ProjectFieldStateResolver
{
	public const STATE_AVAILABLE = 'available';
	public const STATE_HIDDEN = 'hidden';
	public const STATE_DELETED = 'deleted';

	public const SOURCE_DIRECT = 'direct';
	public const SOURCE_CONSTANT = 'constant';

	private const CONSTANT_EXPRESSION_PATTERN = '/^\{=Constant:([A-Za-z0-9_]+)\}$/';

	private array $stateCache = [];

	public function __construct(private ?ProjectAvailabilitySource $projectAvailabilitySource = null)
	{}

	public function resolve(
		mixed $value,
		int $userId,
		array $workflowConstants = [],
	): ?array
	{
		$resolved = $this->resolveValue($value, $workflowConstants);
		if ($resolved === null)
		{
			return null;
		}

		return $this->buildStateForProjectIds(
			$resolved['projectIds'],
			$userId,
			$resolved['source'],
		);
	}

	public function resolveDirect(
		mixed $value,
		int $userId,
	): ?array
	{
		return $this->buildStateForProjectIds(
			$this->extractProjectIds($value),
			$userId,
			self::SOURCE_DIRECT,
		);
	}

	/**
	 * @param list<mixed> $values
	 */
	public function preload(
		array $values,
		int $userId,
		array $workflowConstants = [],
	): void
	{
		if ($userId <= 0 || $values === [])
		{
			return;
		}

		$projectIds = [];
		foreach ($values as $value)
		{
			$resolved = $this->resolveValue($value, $workflowConstants);
			if ($resolved === null)
			{
				continue;
			}

			array_push($projectIds, ...$resolved['projectIds']);
		}

		$this->preloadProjectStates(
			array_values(array_unique($projectIds)),
			$userId,
		);
	}

	/**
	 * @param list<mixed> $values
	 */
	public function preloadDirect(
		array $values,
		int $userId,
	): void
	{
		if ($userId <= 0 || $values === [])
		{
			return;
		}

		$projectIds = [];
		foreach ($values as $value)
		{
			array_push($projectIds, ...$this->extractProjectIds($value));
		}

		$this->preloadProjectStates(
			array_values(array_unique($projectIds)),
			$userId,
		);
	}

	/**
	 * @return list<int>
	 */
	public function extractProjectIds(mixed $value): array
	{
		if (is_array($value))
		{
			$projectIds = [];
			foreach ($value as $item)
			{
				array_push($projectIds, ...$this->extractProjectIds($item));
			}

			return array_values(array_unique($projectIds));
		}

		if (!is_scalar($value) || $value === '')
		{
			return [];
		}

		$value = (string)$value;
		if (preg_match('/^[0-9]+$/D', $value) !== 1)
		{
			return [];
		}

		$projectId = (int)$value;

		return $projectId > 0 ? [$projectId] : [];
	}

	public function getProjectValueTrustKey(mixed $value): ?string
	{
		if (is_string($value) && preg_match(self::CONSTANT_EXPRESSION_PATTERN, $value) === 1)
		{
			return 'expr:' . $value;
		}

		$values = array_map('strval', $this->extractProjectIds($value));
		if ($values === [])
		{
			return null;
		}

		sort($values, SORT_STRING);

		return 'ids:' . implode(',', array_values(array_unique($values)));
	}

	private function resolveValue(mixed $value, array $workflowConstants): ?array
	{
		if (is_string($value) && preg_match(self::CONSTANT_EXPRESSION_PATTERN, $value, $matches) === 1)
		{
			$constant = $workflowConstants[$matches[1]] ?? null;
			if (!is_array($constant))
			{
				return null;
			}

			$type = (string)($constant['Type'] ?? $constant['type'] ?? '');
			if ($type !== ProjectType::getType())
			{
				return null;
			}

			$projectIds = $this->extractProjectIds($constant['Default'] ?? $constant['default'] ?? null);
			if ($projectIds === [])
			{
				return null;
			}

			return [
				'projectIds' => $projectIds,
				'source' => self::SOURCE_CONSTANT,
			];
		}

		$projectIds = $this->extractProjectIds($value);
		if ($projectIds === [])
		{
			return null;
		}

		return [
			'projectIds' => $projectIds,
			'source' => self::SOURCE_DIRECT,
		];
	}

	/**
	 * @param list<int> $projectIds
	 */
	private function buildStateForProjectIds(
		array $projectIds,
		int $userId,
		string $source,
	): ?array
	{
		$this->preloadProjectStates($projectIds, $userId);

		$firstResolvedState = null;
		$deletedState = null;
		$deletedValues = [];
		foreach ($projectIds as $projectId)
		{
			$state = $this->buildState($projectId, $userId, $source);
			if ($state === null)
			{
				continue;
			}

			if ($state['state'] === self::STATE_DELETED)
			{
				$deletedState ??= $state;
				$deletedValues[] = $projectId;

				continue;
			}

			$firstResolvedState ??= $state;
		}

		if ($deletedState !== null)
		{
			$deletedState['deletedValues'] = $deletedValues;

			return $deletedState;
		}

		return $firstResolvedState;
	}

	/**
	 * @param list<int> $projectIds
	 */
	private function preloadProjectStates(array $projectIds, int $userId): void
	{
		if ($userId <= 0 || $projectIds === [])
		{
			return;
		}

		$source = $this->getProjectAvailabilitySource();
		$source->preloadExistence($projectIds);

		$existingProjectIds = [];
		foreach ($projectIds as $projectId)
		{
			if ($source->projectExists($projectId) === true)
			{
				$existingProjectIds[] = $projectId;
			}
		}

		$source->preloadVisibility($existingProjectIds, $userId);
	}

	private function buildState(
		int $projectId,
		int $userId,
		string $source,
	): ?array
	{
		if ($userId <= 0)
		{
			return null;
		}

		$cacheKey = $userId . ':' . $projectId . ':' . $source;
		if (array_key_exists($cacheKey, $this->stateCache))
		{
			return $this->stateCache[$cacheKey];
		}

		$availabilitySource = $this->getProjectAvailabilitySource();
		$exists = $availabilitySource->projectExists($projectId);
		if ($exists === null)
		{
			$this->stateCache[$cacheKey] = null;

			return null;
		}

		$state = self::STATE_DELETED;
		if ($exists)
		{
			$state = $availabilitySource->projectVisible($projectId, $userId)
				? self::STATE_AVAILABLE
				: self::STATE_HIDDEN
			;
		}

		$this->stateCache[$cacheKey] = $this->buildProjectState($state, $projectId, $source);

		return $this->stateCache[$cacheKey];
	}

	private function buildProjectState(string $state, int $projectId, string $source): array
	{
		return [
			'type' => ProjectType::getType(),
			'state' => $state,
			'resolvedValue' => $projectId,
			'source' => $source,
		];
	}

	private function getProjectAvailabilitySource(): ProjectAvailabilitySource
	{
		return $this->projectAvailabilitySource ??= new WorkgroupProjectAvailabilitySource();
	}
}
