<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Internal\Digest\DigestPeriodSelection;
use Bitrix\BIConnector\Internal\Digest\Dto\DigestSetupPreset;
use Bitrix\BIConnector\Internal\Digest\Period\ScreenPeriodRecognizer;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestDashboardProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\ScopeValueAccessChecker;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter\BPWorkflowTemplate;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter\DateTime;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter\TasksFlowsFlow;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;
use Bitrix\BIConnector\Superset\Logger\Logger;

/**
 * Reads the state of a report screen into the initial values of the digest setup wizard.
 *
 * The state comes from the browser, so it is taken as an intention only: a filter counts by its id in the
 * native filter configuration of the report, the period has to be recognized, a scope value has to belong
 * to a parameter the report declares and be visible to the user. Whatever fails a check is dropped without
 * a word: the wizard opens either way, and a field left empty is better than a field filled with a guess.
 */
final class ScreenStateInterpreter
{
	private const NO_FILTER = 'no filter';
	private const MAX_LOGGED_EXPRESSION_LENGTH = 200;

	private readonly \Closure $logUnrecognizedPeriod;

	/**
	 * @param \Closure(string): void|null $logUnrecognizedPeriod Receives a time range the recognizer refused.
	 */
	public function __construct(
		private readonly ScreenPeriodRecognizer $periodRecognizer,
		private readonly ScopeValueAccessChecker $scopeValueAccessChecker,
		private readonly DigestDashboardProvider $dashboardProvider,
		?\Closure $logUnrecognizedPeriod = null,
		private readonly PeriodTrimmer $periodTrimmer = new PeriodTrimmer(),
	)
	{
		$this->logUnrecognizedPeriod = $logUnrecognizedPeriod ?? static function (string $timeRange): void {
			Logger::logInfo('BI digest setup: report screen period is not recognized', [
				'time_range' => mb_substr($timeRange, 0, self::MAX_LOGGED_EXPRESSION_LENGTH),
			]);
		};
	}

	/**
	 * @param array<string, mixed> $nativeFilterState Native filter id => its state as the screen reported it.
	 */
	public function interpret(Dashboard $dashboard, array $nativeFilterState, int $userId): DigestSetupPreset
	{
		$filterTypes = $this->collectFilterTypes($dashboard);

		$timeRanges = [];
		$scopeCandidates = [];
		foreach ($nativeFilterState as $filterId => $state)
		{
			$filterType = $filterTypes[(string)$filterId] ?? null;
			if ($filterType === null || !is_array($state))
			{
				continue;
			}

			if ($filterType === DateTime::getFilterType())
			{
				$timeRange = $this->readTimeRange($state);
				if ($timeRange !== null)
				{
					$timeRanges[] = $timeRange;
				}

				continue;
			}

			$parameter = $this->getScopeFilterParameters()[$filterType] ?? null;
			if ($parameter !== null)
			{
				$scopeCandidates[$parameter->code()][] = $this->readScopeValue($state, $filterType);
			}
		}

		return new DigestSetupPreset(
			period: $this->resolvePeriod($timeRanges),
			scopeValues: $this->resolveScopeValues($dashboard->getId(), $scopeCandidates, $userId),
		);
	}

	/**
	 * @return array<string, string> Native filter id => filter type.
	 */
	private function collectFilterTypes(Dashboard $dashboard): array
	{
		$types = [];
		foreach ($dashboard->getNativeFiltersConfig() as $filterConfig)
		{
			$id = is_array($filterConfig) ? ($filterConfig['id'] ?? null) : null;
			$type = is_array($filterConfig) ? ($filterConfig['filterType'] ?? null) : null;
			if (is_string($id) && $id !== '' && is_string($type))
			{
				$types[$id] = $type;
			}
		}

		return $types;
	}

	/**
	 * The same filter types {@see \Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter\NativeFilterBuilder}
	 * fills from the scope parameters of the report url.
	 *
	 * @return array<string, Parameter> Filter type => scope parameter.
	 */
	private function getScopeFilterParameters(): array
	{
		return [
			BPWorkflowTemplate::getFilterType() => Parameter::WorkflowTemplateId,
			TasksFlowsFlow::getFilterType() => Parameter::TasksFlowsFlowId,
		];
	}

	private function readTimeRange(array $state): ?string
	{
		$timeRange = $state['extraFormData']['time_range'] ?? $state['filterState']['value'] ?? null;
		if (!is_string($timeRange))
		{
			return null;
		}

		$timeRange = trim($timeRange);

		return $timeRange === '' || mb_strtolower($timeRange) === self::NO_FILTER ? null : $timeRange;
	}

	/**
	 * Several date filters of one screen are one answer only when they agree. A range that is set but not
	 * recognized may disagree with the others just as well, so it leaves the period of the screen unknown.
	 *
	 * @param string[] $timeRanges
	 */
	private function resolvePeriod(array $timeRanges): ?string
	{
		$periods = [];
		$hasUnrecognized = false;
		foreach ($timeRanges as $timeRange)
		{
			$selection = $this->periodRecognizer->recognize($timeRange);
			if ($selection === null)
			{
				($this->logUnrecognizedPeriod)($timeRange);
				$hasUnrecognized = true;

				continue;
			}

			$periods[$this->trim($selection)->toStored()] = true;
		}

		return !$hasUnrecognized && count($periods) === 1 ? (string)array_key_first($periods) : null;
	}

	/**
	 * The user sees in the wizard exactly the range the run will pin, so a range longer than a year is
	 * narrowed already here, the same way the run narrows it.
	 */
	private function trim(DigestPeriodSelection $selection): DigestPeriodSelection
	{
		$bounds = $selection->toBounds();
		$trimmed = $bounds === null ? null : $this->periodTrimmer->trim($bounds);

		return $trimmed === null ? $selection : DigestPeriodSelection::fromBounds($trimmed);
	}

	/**
	 * A digest covers exactly one value of a parameter, so a filter with several values picked, or two
	 * filters of one parameter disagreeing, give no value at all.
	 */
	private function readScopeValue(array $state, string $filterType): ?int
	{
		$value = $state['filterState']['value'] ?? null;
		if ($value === null || $value === [])
		{
			$value = $this->readExtraFormDataValue($state, $filterType);
		}

		if (is_array($value))
		{
			$value = count($value) === 1 ? reset($value) : null;
		}

		if (is_int($value))
		{
			return $value > 0 ? $value : null;
		}

		return is_string($value) && preg_match('/^[1-9]\d{0,17}$/', $value) ? (int)$value : null;
	}

	private function readExtraFormDataValue(array $state, string $filterType): mixed
	{
		$column = match ($filterType)
		{
			BPWorkflowTemplate::getFilterType() => BPWorkflowTemplate::getColumnName(),
			TasksFlowsFlow::getFilterType() => TasksFlowsFlow::getColumnName(),
			default => null,
		};

		$filters = $state['extraFormData']['filters'] ?? null;
		if ($column === null || !is_array($filters))
		{
			return null;
		}

		foreach ($filters as $filter)
		{
			if (is_array($filter) && ($filter['col'] ?? null) === $column && ($filter['op'] ?? null) === 'IN')
			{
				return $filter['val'] ?? null;
			}
		}

		return null;
	}

	/**
	 * @param array<string, array<int|null>> $candidates Parameter code => values its filters carried.
	 *
	 * @return array<string, int>
	 */
	private function resolveScopeValues(int $dashboardId, array $candidates, int $userId): array
	{
		if ($candidates === [])
		{
			return [];
		}

		$declared = $this->dashboardProvider->findDashboard($dashboardId)?->scopeParameterCodes ?? [];

		$scopeValues = [];
		foreach ($candidates as $code => $values)
		{
			$values = array_unique($values);
			$value = count($values) === 1 ? reset($values) : null;
			$parameter = Parameter::tryFrom((string)$code);
			if (
				$value === null
				|| $parameter === null
				|| !in_array($parameter->code(), $declared, true)
				|| !$this->scopeValueAccessChecker->isValueAccessible($parameter, $userId, $value)
			)
			{
				continue;
			}

			$scopeValues[$parameter->code()] = $value;
		}

		return $scopeValues;
	}
}
