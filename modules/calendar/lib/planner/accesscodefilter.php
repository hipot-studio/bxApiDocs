<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Planner;

use Bitrix\Calendar\Integration\HumanResources\TeamAccessCode;
use Bitrix\Calendar\Integration\HumanResources\TeamAccessService;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Helper\Workgroup\Access as WorkgroupAccess;

final class AccessCodeFilter
{
	private const PROJECT_PREFIX = 'SG';

	public function __construct(
		private readonly int $userId,
	)
	{
	}

	public function filter(array $codes): array
	{
		$filteredCodes = [];
		$processedEntities = [];
		$groupAccess = [];
		$viewableTeamIndex = $this->getViewableTeamIndex($codes);

		foreach ($codes as $code)
		{
			if (!is_string($code))
			{
				continue;
			}

			if ($code === 'UA')
			{
				if (!isset($processedEntities[$code]))
				{
					$processedEntities[$code] = true;
					$filteredCodes[] = $code;
				}

				continue;
			}

			if (preg_match('/^U([0-9]+)\z/', $code, $match) === 1)
			{
				$userId = $this->parsePositiveId($match[1]);
				if ($userId === null)
				{
					continue;
				}

				$entityKey = 'U:' . $userId;
				if (!isset($processedEntities[$entityKey]))
				{
					$processedEntities[$entityKey] = true;
					$filteredCodes[] = 'U' . $userId;
				}

				continue;
			}

			if (str_starts_with($code, TeamAccessCode::PREFIX))
			{
				$nodeId = preg_match('/^' . TeamAccessCode::PREFIX . '([0-9]+)\z/', $code, $match) === 1
					? $this->parsePositiveId($match[1])
					: null
				;
				if ($nodeId === null || $this->userId <= 0)
				{
					continue;
				}

				$entityKey = TeamAccessCode::PREFIX . ':' . $nodeId;
				if (isset($processedEntities[$entityKey]))
				{
					continue;
				}
				$processedEntities[$entityKey] = true;

				if (isset($viewableTeamIndex[$nodeId]))
				{
					$filteredCodes[] = TeamAccessCode::fromNodeId($nodeId);
				}

				continue;
			}

			if (str_starts_with($code, self::PROJECT_PREFIX))
			{
				$groupCode = $this->parseGroupCode($code);
				if ($groupCode === null || $this->userId <= 0)
				{
					continue;
				}
				[$groupId, $role] = $groupCode;

				$entityKey = self::PROJECT_PREFIX . ':' . $groupId . ':' . $role;
				if (isset($processedEntities[$entityKey]))
				{
					continue;
				}
				$processedEntities[$entityKey] = true;

				if (!array_key_exists($groupId, $groupAccess))
				{
					$groupAccess[$groupId] = $this->canViewWorkgroup($groupId);
				}

				if ($groupAccess[$groupId])
				{
					$filteredCodes[] = self::PROJECT_PREFIX
						. $groupId
						. ($role === '' ? '' : '_' . $role)
					;
				}

				continue;
			}

			if (preg_match('/^DR([0-9]+)\z/', $code, $match) === 1)
			{
				$departmentId = $this->parsePositiveId($match[1]);
				if ($departmentId === null)
				{
					continue;
				}

				$entityKey = 'DR:' . $departmentId;
				if (!isset($processedEntities[$entityKey]))
				{
					$processedEntities[$entityKey] = true;
					$filteredCodes[] = 'DR' . $departmentId;
				}
			}
		}

		return $filteredCodes;
	}

	private function getViewableTeamIndex(array $codes): array
	{
		if ($this->userId <= 0)
		{
			return [];
		}

		$nodeIds = [];
		foreach ($codes as $code)
		{
			if (!is_string($code) || !str_starts_with($code, TeamAccessCode::PREFIX))
			{
				continue;
			}

			$nodeId = preg_match('/^' . TeamAccessCode::PREFIX . '([0-9]+)\z/', $code, $match) === 1
				? $this->parsePositiveId($match[1])
				: null
			;
			if ($nodeId !== null)
			{
				$nodeIds[$nodeId] = $nodeId;
			}
		}

		if (empty($nodeIds))
		{
			return [];
		}

		try
		{
			return array_fill_keys(
				(new TeamAccessService())->getViewableTeamIds(array_values($nodeIds), $this->userId),
				true,
			);
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	private function canViewWorkgroup(int $groupId): bool
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return false;
		}

		try
		{
			return WorkgroupAccess::canView([
				'groupId' => $groupId,
				'userId' => $this->userId,
			]);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function parseGroupCode(string $code): ?array
	{
		if (preg_match('/^' . self::PROJECT_PREFIX . '([0-9]+)(?:_?([AEKMO]))?\z/', $code, $match) !== 1)
		{
			return null;
		}

		$groupId = $this->parsePositiveId($match[1]);
		if ($groupId === null)
		{
			return null;
		}

		return [$groupId, $match[2] ?? ''];
	}

	private function parsePositiveId(string $value): ?int
	{
		$normalizedValue = ltrim($value, '0');
		if ($normalizedValue === '')
		{
			return null;
		}

		$maxValue = (string)PHP_INT_MAX;
		if (
			strlen($normalizedValue) > strlen($maxValue)
			|| (
				strlen($normalizedValue) === strlen($maxValue)
				&& strcmp($normalizedValue, $maxValue) > 0
			)
		)
		{
			return null;
		}

		return (int)$normalizedValue;
	}
}
