<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

use Bitrix\Main\Loader;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Tasks\Flow\Provider\FlowProvider;
use Bitrix\Tasks\Flow\Provider\Query\ExpandedFlowQuery;

class TasksFlowsFlow extends PresetFilter
{
	/**
	 * @inheritDoc
	 */
	public static function getFilterType(): string
	{
		return 'filter_tasks_flow';
	}

	/**
	 * @inheritDoc
	 */
	public static function getColumnName(): string
	{
		return 'flow_id';
	}

	public static function getDatasetName(): string
	{
		return 'system_filter_tasks_flow';
	}

	/**
	 * @inheritDoc
	 */
	public function getValues(): PresetValueCollection
	{
		if (!Loader::includeModule('tasks'))
		{
			return new PresetValueCollection();
		}

		static $presetValueCollection = null;
		if ($presetValueCollection)
		{
			return $presetValueCollection;
		}

		$userId = CurrentUser::get()->getId();
		if (!$userId)
		{
			return new PresetValueCollection();
		}

		$presetValueCollection = new PresetValueCollection();

		$provider = new FlowProvider();
		$query = new ExpandedFlowQuery($userId);
		$query
			->setSelect(['ID', 'NAME'])
			->setOrderBy(['ID' => 'ASC'])
		;

		$flows = $provider->getList($query);

		foreach ($flows as $flow)
		{
			$presetValueCollection->set(
				$flow->getId(),
				new PresetValue(
					value: $flow->getId(),
					label: sprintf('[%d] %s', $flow->getId(), $flow->getName()),
				),
			);
		}

		return $presetValueCollection;
	}

	/**
	 * @inheritDoc
	 */
	public function isAvailable(): bool
	{
		return Loader::includeModule('tasks') && parent::isAvailable();
	}
}
