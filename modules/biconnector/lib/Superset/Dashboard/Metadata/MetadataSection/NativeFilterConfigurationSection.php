<?php

namespace Bitrix\BIConnector\Superset\Dashboard\Metadata\MetadataSection;

use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\Main\Localization\Loc;

class NativeFilterConfigurationSection implements MetadataSectionInterface
{
	private array $filters = [];

	public function __construct(array $filterIdList = [])
	{
		foreach ($filterIdList as $filterId)
		{
			if (!is_string($filterId))
			{
				continue;
			}

			$this->addFilter($filterId);
		}
	}

	public function getSectionKey(): string
	{
		return 'native_filter_configuration';
	}

	public function build(): array
	{
		return $this->filters;
	}

	public function isEmpty(): bool
	{
		return empty($this->filters);
	}

	public function addFilter(string $filterId): void
	{
		$filter = $this->createFilter($filterId);
		if (empty($filter))
		{
			return;
		}

		$this->filters[] = $filter;
	}


	private function createFilter(string $filterId): array
	{
		$class = $this->getFilterClassById($filterId);
		if ($class === null)
		{
			return [];
		}

		$datasetId = $class::getDatasetId();

		if ($datasetId === null)
		{
			return [];
		}

		$filterData = [
			'id' => uniqid('NATIVE_FILTER-', true),
			'name' => Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_EMBEDDED_FILTER_NAME_' . strtoupper($class::getFilterType())),
			'filterType' => $class::getFilterType(),
			'targets' => [
				[
					'column' => ['name' => $class::getColumnName()],
					'datasetId' => $datasetId,
				],
			],
		];

		return array_replace_recursive($this->getEmptyFilter(), $filterData);
	}

	/**
	 * @param string $filterId
	 * @return class-string<EmbeddedFilter\PresetFilter>|null
	 */
	private function getFilterClassById(string $filterId): ?string
	{
		return match ($filterId)
		{
			'workflow_template_id' => EmbeddedFilter\BPWorkflowTemplate::class,
			'tasks_flows_flow_id' => EmbeddedFilter\TasksFlowsFlow::class,
			default => null,
		};
	}

	/**
	 * Returns a base native filter configuration structure.
	 *
	 * This structure is tailored for Superset 4.x
	 * TODO: Review and update this structure when upgrading to Superset 5+.
	 *
	 * @return array<string, mixed>
	 */
	private function getEmptyFilter(): array
	{
		return [
			'id' => '',
			'name' => '',
			'filterType' => '',
			'targets' => [
				[
					'datasetId' => 0,
					'column' => [
						'name' => '',
					],
				],
			],
			'scope' => [
				'rootPath' => ['ROOT_ID'],
				'excluded' => [],
			],
			'chartsInScope' => [],
			'tabsInScope' => [],
			'controlValues' => [
				'enableEmptyFilter' => false,
				'defaultToFirstItem' => false,
				'multiSelect' => true,
				'searchAllOptions' => false,
				'inverseSelection' => false,
			],
			'defaultDataMask' => [
				'extraFormData' => [],
				'filterState' => [],
				'ownState' => [],
			],
			'cascadeParentIds' => [],
			'type' => 'NATIVE_FILTER',
			'description' => '',
		];
	}
}
