<?php

namespace Bitrix\BIConnector\Integration\Tasks;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;
use Bitrix\Tasks\Flow\Distribution\FlowDistributionType;

class Flow extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'FLOW_FIELD_';

	protected function getResultTableName(): string
	{
		return 'flow';
	}

	public function getSqlTableAlias(): string
	{
		return 'TF';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_tasks_flow';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('FLOW_TABLE');
	}

	protected function getTableDescriptionFull(): string
	{
		return $this->getMessage('FLOW_TABLE_DESCRIPTION_FULL', '');
	}

	/**
	 * @return Result
	 */
	protected function onBeforeEvent(): Result
	{
		$result = parent::onBeforeEvent();

		if (!Loader::includeModule('tasks'))
		{
			$result->addError(new Error('Module is not installed'));
		}

		return $result;
	}

	protected function getFields(): array
	{
		/** @var \Bitrix\Main\DB\SqlHelper&\Bitrix\BIConnector\DB\BiSqlHelperInterface $helper */
		$helper = $this->getSqlHelper();

		$creatorJoin = $this->createJoin(
			'CREATOR',
			"INNER JOIN b_user CREATOR ON CREATOR.ID = {$this->getAliasFieldName('CREATOR_ID')}",
			"LEFT JOIN b_user CREATOR ON CREATOR.ID = {$this->getAliasFieldName('CREATOR_ID')}",
		);

		$ownerJoin = $this->createJoin(
			'OWNER',
			"INNER JOIN b_user OWNER ON OWNER.ID = {$this->getAliasFieldName('OWNER_ID')}",
			"LEFT JOIN b_user OWNER ON OWNER.ID = {$this->getAliasFieldName('OWNER_ID')}",
		);

		$groupJoin = $this->createJoin(
			'SGROUP',
			"INNER JOIN b_sonet_group SGROUP ON SGROUP.ID = {$this->getAliasFieldName('GROUP_ID')}",
			"LEFT JOIN b_sonet_group SGROUP ON SGROUP.ID = {$this->getAliasFieldName('GROUP_ID')}",
		);

		$tasksJoin = $this->createJoin(
			'TFT',
			"INNER JOIN b_tasks_flow_task TFT ON TFT.FLOW_ID = {$this->getAliasFieldName('ID')}",
			"LEFT JOIN b_tasks_flow_task TFT ON TFT.FLOW_ID = {$this->getAliasFieldName('ID')}",
		);

		$expiredTasksJoin = $this->createJoin(
			'EXPIRED',
			"INNER JOIN (
						SELECT BTFT.TASK_ID AS EXPIRED_TASKS_IDS, BTFT.FLOW_ID
						FROM b_tasks_effective BTE
							INNER JOIN b_tasks_flow_task BTFT ON BTE.TASK_ID = BTFT.TASK_ID
						WHERE BTE.IS_VIOLATION = 'Y'
					) EXPIRED ON EXPIRED.FLOW_ID = {$this->getAliasFieldName('ID')}",
			"LEFT JOIN (
						SELECT BTFT.TASK_ID AS EXPIRED_TASKS_IDS, BTFT.FLOW_ID
						FROM b_tasks_effective BTE
							INNER JOIN b_tasks_flow_task BTFT ON BTE.TASK_ID = BTFT.TASK_ID
						WHERE BTE.IS_VIOLATION = 'Y'
					) EXPIRED ON EXPIRED.FLOW_ID = {$this->getAliasFieldName('ID')}",
		);

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new StringField('NAME')),
			(new StringField('FLOW'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('ID')} > 0 THEN
						concat_ws(
							' ',
							{$helper->getConcatFunction("'['", $this->getAliasFieldName('ID'), "']'")},
							nullif({$this->getAliasFieldName('NAME')}, '')
						)
					ELSE NULL END"
				)
			,
			(new IntegerField('CREATOR_ID'))
				->setName($this->getAliasFieldName('CREATOR_ID'))
			,
			(new StringField('CREATOR_NAME'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('CREATOR_ID')} > 0 THEN
						concat_ws(
							' ',
							nullif({$creatorJoin->getJoinFieldName('NAME')}, ''),
							nullif({$creatorJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($creatorJoin)
			,
			(new StringField('CREATOR'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('CREATOR_ID')} > 0 THEN
						concat_ws(
							' ',
							{$helper->getConcatFunction("'['", $this->getAliasFieldName('CREATOR_ID'), "']'")},
							nullif({$creatorJoin->getJoinFieldName('NAME')}, ''),
							nullif({$creatorJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($creatorJoin)
			,
			(new IntegerField('OWNER_ID'))
				->setName($this->getAliasFieldName('OWNER_ID'))
			,
			(new StringField('OWNER_NAME'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('OWNER_ID')} > 0 THEN
						concat_ws(
							' ',
							nullif({$ownerJoin->getJoinFieldName('NAME')}, ''),
							nullif({$ownerJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($ownerJoin)
			,
			(new StringField('OWNER'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('OWNER_ID')} > 0 THEN
						concat_ws(
							' ',
							{$helper->getConcatFunction("'['", $this->getAliasFieldName('OWNER_ID'), "']'")},
							nullif({$ownerJoin->getJoinFieldName('NAME')}, ''),
							nullif({$ownerJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($ownerJoin)
			,
			(new IntegerField('PLANNED_COMPLETION_TIME'))
				->setMetric()
			,
			(new StringField('DISTRIBUTION_TYPE'))
				->setDictionary([
					FlowDistributionType::MANUALLY->value => $this->getMessage('FLOW_FIELD_DISTRIBUTION_TYPE_VALUE_TYPE_MANUALLY'),
					FlowDistributionType::QUEUE->value => $this->getMessage('FLOW_FIELD_DISTRIBUTION_TYPE_VALUE_TYPE_QUEUE'),
					FlowDistributionType::HIMSELF->value => $this->getMessage('FLOW_FIELD_DISTRIBUTION_TYPE_VALUE_TYPE_HIMSELF'),
				]),
			(new StringField('HAS_TEMPLATE'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('TEMPLATE_ID')} >= 1 THEN 'Y' ELSE 'N' END"
				),
			(new StringField('ACTIVE'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('ACTIVE')} >= 1 THEN 'Y' ELSE 'N' END"
				),
			(new IntegerField('GROUP_ID')),
			(new StringField('GROUP_NAME'))
				->setName($groupJoin->getJoinFieldName('NAME'))
				->setJoin($groupJoin)
			,
			(new StringField('GROUP_INFO'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('GROUP_ID')} > 0 THEN
						concat_ws(
							' ',
							{$helper->getConcatFunction("'['", $this->getAliasFieldName('GROUP_ID'), "']'")},
							nullif({$groupJoin->getJoinFieldName('NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($groupJoin)
			,
			(new StringField('TASKS_IDS'))
				->setName($tasksJoin->getJoinFieldName('TASK_ID'))
				->setJoin($tasksJoin)
				->setMultiple()
			,
			(new StringField('EXPIRED_TASKS_IDS'))
				->setName($expiredTasksJoin->getJoinFieldName('EXPIRED_TASKS_IDS'))
				->setJoin($expiredTasksJoin)
				->setMultiple()
			,
		];
	}
}
