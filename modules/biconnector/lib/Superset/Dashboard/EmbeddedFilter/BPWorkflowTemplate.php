<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

use Bitrix\Main\Loader;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable;
use Bitrix\Bizproc\Workflow\Entity\WorkflowUserTable;

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
	protected function getColumnName(): string
	{
		return 'workflow_template_id';
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

		$userId = CurrentUser::get()->getId();
		if (!$userId)
		{
			return new PresetValueCollection();
		}

		$presetValueCollection = new PresetValueCollection();

		$stateQuery = WorkflowStateTable::query();
		$stateQuery->setSelect([
			'TID' => 'WORKFLOW_TEMPLATE_ID',
			'TNAME' => 'TEMPLATE.NAME',
		]);
		$stateQuery->addOrder('TID');

		$subQuery = WorkflowUserTable::query();
		$subQuery->addSelect('WORKFLOW_ID');
		$subQuery->addFilter('USER_ID', $userId);

		$stateQuery->registerRuntimeField('',
			new ReferenceField('M',
				\Bitrix\Main\ORM\Entity::getInstanceByQuery($subQuery),
				['=this.ID' => 'ref.WORKFLOW_ID'],
				['join_type' => 'INNER'],
			),
		);

		$workflows = $stateQuery->exec();
		while ($workflow = $workflows->fetch())
		{
			$id = (int)$workflow['TID'];

			$presetValueCollection->set(
				$id,
				new PresetValue(
					value: $id,
					label: sprintf('[%d] %s', $id, $workflow['TNAME']),
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
