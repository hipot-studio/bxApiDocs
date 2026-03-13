<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;

class NativeFilterBuilder
{
	/** @var array<string, UrlFilter[]> */
	private array $filters = [];

	public function __construct(private readonly Dashboard $dashboard)
	{
		$this->initFilters();
	}

	private function initFilters(): void
	{
		$config = $this->dashboard->getNativeFiltersConfig();

		foreach ($config as $filterConfig)
		{
			if (isset($filterConfig['filterType']))
			{
				$filter = $this->getFilterByType($filterConfig['filterType'], $filterConfig['id']);
				if ($filter && $filter->isAvailable())
				{
					$this->filters[$filter::getFilterType()][] = $filter;
				}
			}
		}
	}

	private function getFilterByType($type, ?string $filterId): ?UrlFilter
	{
		return match ($type)
		{
			DateTime::getFilterType() => new DateTime($this->dashboard, $filterId),
			BPWorkflowTemplate::getFilterType() => new BPWorkflowTemplate($this->dashboard, $filterId),
			TasksFlowsFlow::getFilterType() => new TasksFlowsFlow($this->dashboard, $filterId),
			CurrentUser::getFilterType() => new CurrentUser($this->dashboard, $filterId),
			default => null,
		};
	}

	/**
	 * @param array<string, int> $urlParams
	 * @return void
	 */
	public function setValuesFromUrlParameters(array $urlParams): void
	{
		if ($urlParams)
		{
			$urlParameterFilterMap = [
				Parameter::WorkflowTemplateId->code() => BPWorkflowTemplate::getFilterType(),
				Parameter::TasksFlowsFlowId->code() => TasksFlowsFlow::getFilterType(),
			];

			foreach ($urlParams as $code => $value)
			{
				if (isset($urlParameterFilterMap[$code], $this->filters[$urlParameterFilterMap[$code]]))
				{
					foreach ($this->filters[$urlParameterFilterMap[$code]] as $filter)
					{
						/** @var PresetFilter $filter */
						$filter->setValue((int)$value);
					}
				}
			}
		}
	}

	/**
	 * Returns the formatted filter string for the Superset dashboard.
	 *
	 * @return string
	 */
	public function getFormattedFilter(): string
	{
		$formatted = [];

		foreach ($this->filters as $filtersByType)
		{
			foreach ($filtersByType as $filter)
			{
				/** @var UrlFilter $filter */
				$formatted[] = $filter->getFormatted();
			}
		}

		return sprintf('(%s)', implode(',', array_filter($formatted)));
	}

	/**
	 * Returns an array of preset filter options.
	 *
	 * @return array
	 */
	public function getPresetFilterOptions(): array
	{
		$result = [];

		foreach ($this->filters as $filtersByType)
		{
			foreach ($filtersByType as $filter)
			{
				if ($filter instanceof PresetFilter)
				{
					/** @var PresetFilter $filter */
					$result[$filter->getCode()] = $filter->getValues()->toArray();
				}
			}
		}

		return $result;
	}
}
