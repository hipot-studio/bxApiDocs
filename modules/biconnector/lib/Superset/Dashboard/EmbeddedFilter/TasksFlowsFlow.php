<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

use Bitrix\BIConnector\Public\Provider\ScopeAccessibleValueProvider;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\DI\ServiceLocator;

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

		$userId = (int)CurrentUser::get()->getId();
		if (!$userId)
		{
			return new PresetValueCollection();
		}

		$presetValueCollection = new PresetValueCollection();

		/** @var ScopeAccessibleValueProvider $valueProvider */
		$valueProvider = ServiceLocator::getInstance()->get('biconnector.provider.scopeAccessibleValue');
		$values = $valueProvider->getList(Parameter::TasksFlowsFlowId, $userId, limit: 0);
		foreach ($values as $value)
		{
			$presetValueCollection->set(
				$value['id'],
				new PresetValue(
					value: $value['id'],
					label: sprintf('[%d] %s', $value['id'], $value['name']),
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
