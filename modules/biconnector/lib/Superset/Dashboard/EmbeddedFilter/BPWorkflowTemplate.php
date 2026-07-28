<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

use Bitrix\BIConnector\Public\Provider\ScopeAccessibleValueProvider;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\DI\ServiceLocator;

class BPWorkflowTemplate extends PresetFilter
{
	/**
	 * @inheritDoc
	 */
	public static function getFilterType(): string
	{
		return 'filter_bp_workflow_template';
	}

	/**
	 * @inheritDoc
	 */
	public static function getColumnName(): string
	{
		return 'workflow_template_id';
	}

	public static function getDatasetName(): string
	{
		return 'system_filter_bizproc_workflow_template';
	}

	/**
	 * @inheritDoc
	 */
	public function getValues(): PresetValueCollection
	{
		if (!Loader::includeModule('bizproc'))
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
		$values = $valueProvider->getList(Parameter::WorkflowTemplateId, $userId, limit: 0);
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
		return Loader::includeModule('bizproc') && parent::isAvailable();
	}
}
