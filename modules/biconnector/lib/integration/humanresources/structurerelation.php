<?php

namespace Bitrix\BIConnector\Integration\HumanResources;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;

class StructureRelation extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'HR_BIC_STRUCTURE_RELATION_FIELD_';

	protected function getResultTableName(): string
	{
		return 'org_structure_relation';
	}

	public function getSqlTableAlias(): string
	{
		return 'HSNP';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_hr_structure_node_path';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('HR_BIC_STRUCTURE_RELATION_TABLE');
	}

	/**
	 * @return Result
	 */
	protected function onBeforeEvent(): Result
	{
		$result = parent::onBeforeEvent();

		if (!Loader::includeModule('humanresources'))
		{
			$result->addError(new Error('Module is not installed'));
		}

		return $result;
	}

	protected function getFields(): array
	{
		$childNodeJoin = $this->createJoin(
			'CHILD_NODE',
			"INNER JOIN b_hr_structure_node CHILD_NODE ON CHILD_NODE.ID = {$this->getAliasFieldName('CHILD_ID')}",
			"LEFT JOIN b_hr_structure_node CHILD_NODE ON CHILD_NODE.ID = {$this->getAliasFieldName('CHILD_ID')}"
		);

		$parentNodeJoin = $this->createJoin(
			'PARENT_NODE',
			"INNER JOIN b_hr_structure_node PARENT_NODE ON PARENT_NODE.ID = {$this->getAliasFieldName('PARENT_ID')}",
			"LEFT JOIN b_hr_structure_node PARENT_NODE ON PARENT_NODE.ID = {$this->getAliasFieldName('PARENT_ID')}"
		);

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new IntegerField('PARENT_ID')),
			(new IntegerField('CHILD_ID')),
			(new IntegerField('DEPTH')),
			(new StringField('PARENT_NODE_NAME'))
				->setName($parentNodeJoin->getJoinFieldName('NAME'))
				->setJoin($parentNodeJoin)
			,
			(new StringField('PARENT_NODE'))
				->setName("
					if(
						{$this->getAliasFieldName('PARENT_ID')} > 0,
						concat_ws(
							' ', 
							concat('[', {$this->getAliasFieldName('PARENT_ID')}, ']'), 
							nullif({$parentNodeJoin->getJoinFieldName('NAME')}, '')
						),
						NULL
					)"
				)
				->setJoin($parentNodeJoin)
			,
			(new StringField('CHILD_NODE_NAME'))
				->setName($childNodeJoin->getJoinFieldName('NAME'))
				->setJoin($childNodeJoin)
			,
			(new StringField('CHILD_NODE'))
				->setName("
					if(
						{$this->getAliasFieldName('CHILD_ID')} > 0,
						concat_ws(
							' ', 
							concat('[', {$this->getAliasFieldName('CHILD_ID')}, ']'), 
							nullif({$childNodeJoin->getJoinFieldName('NAME')}, '')
						),
						NULL
					)"
				)
				->setJoin($childNodeJoin)
			,
		];
	}
}
