<?php

namespace Bitrix\Crm\Service\Timeline\Navigation\Visibility;

use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Navigation\PredicateCompiler;

final class Projection
{
	public function __construct(
		private readonly ?BatchAccessLoader $accessLoader = null,
		private readonly ?PredicateCompiler $compiler = null,
	)
	{
	}

	/** @return ProjectionResult[] */
	public function project(array $rawRows, Context $context): array
	{
		$access = ($this->accessLoader ?? new BatchAccessLoader())->load($rawRows, $context);
		$compiler = $this->compiler ?? new PredicateCompiler();
		$result = [];
		foreach ($rawRows as $index => $row)
		{
			$state = $access[$index];
			$key = $compiler->classify($row);
			$technical = $compiler->isTechnical($row);
			if (!$state['exists'])
			{
				[$placeholderKey, $placeholderTechnical] = $this->placeholderType($row, $compiler);
				$result[] = new ProjectionResult(
					ProjectionResult::PLACEHOLDER,
					$placeholderKey,
					$placeholderTechnical,
					$this->placeholder($row),
				);
				continue;
			}
			if (!$state['canRead'])
			{
				$result[] = new ProjectionResult(ProjectionResult::HIDDEN, null, false, []);
				continue;
			}
			if (!$state['communicationVisible'] || !$state['relatedCanRead'])
			{
				$result[] = new ProjectionResult(
					ProjectionResult::REDACTED,
					$key,
					$technical,
					$this->redacted($row),
				);
				continue;
			}
			if (!$state['available'])
			{
				[$placeholderKey, $placeholderTechnical] = $this->placeholderType($row, $compiler);
				$result[] = new ProjectionResult(
					ProjectionResult::PLACEHOLDER,
					$placeholderKey,
					$placeholderTechnical,
					$this->placeholder($row),
				);
				continue;
			}
			$result[] = new ProjectionResult(
				ProjectionResult::FULL,
				$key,
				$technical,
				[],
				$row['ITEM'] ?? $row,
			);
		}

		return $result;
	}

	private function redacted(array $row): array
	{
		return ['ID' => (int)($row['ID'] ?? 0), 'TYPE' => 'redacted'];
	}

	private function placeholder(array $row): array
	{
		return ['ID' => (int)($row['ID'] ?? 0), 'TYPE' => 'placeholder'];
	}

	private function placeholderType(array $row, PredicateCompiler $compiler): array
	{
		$rawType = $row['TYPE_ID'] ?? null;
		if (
			!is_int($rawType)
			&& (!is_string($rawType) || preg_match('/^[0-9]+$/D', $rawType) !== 1)
		)
		{
			return ['other-event', false];
		}

		$typeId = (int)$rawType;
		if ($typeId <= 0)
		{
			return ['other-event', false];
		}
		if ($typeId === 1)
		{
			$activityTypeId = (int)($row['ACTIVITY_TYPE_ID'] ?? 0);
			$providerId = (string)($row['PROVIDER_ID'] ?? $row['ASSOCIATED_ENTITY_CLASS_NAME'] ?? '');
			if ($activityTypeId <= 0 && $providerId === '')
			{
				return ['other-event', false];
			}
		}

		return [$compiler->classify($row), $compiler->isTechnical($row)];
	}
}
