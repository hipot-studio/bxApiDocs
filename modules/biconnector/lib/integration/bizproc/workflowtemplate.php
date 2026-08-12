<?php

namespace Bitrix\BIConnector\Integration\Bizproc;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;
use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\DatasetFilter;

class WorkflowTemplate extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'WORKFLOW_TEMPLATE_FIELD_';

	protected function getResultTableName(): string
	{
		return 'bizproc_workflow_template';
	}

	public function getSqlTableAlias(): string
	{
		return 'WT';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_bp_workflow_template';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('WORKFLOW_TEMPLATE_TABLE');
	}

	protected function getTableDescriptionFull(): string
	{
		return $this->getMessage('WORKFLOW_TEMPLATE_TABLE_DESCRIPTION_FULL', '');
	}

	protected function onBeforeEvent(): Result
	{
		$result = parent::onBeforeEvent();

		if (!Loader::includeModule('bizproc'))
		{
			$result->addError(new Error('Module is not installed'));
		}

		return $result;
	}

	protected function getFields(): array
	{
		/** @var \Bitrix\Main\DB\SqlHelper&\Bitrix\BIConnector\DB\BiSqlHelperInterface $helper */
		$helper = $this->getSqlHelper();

		$userJoin = $this->createJoin(
			'BP_USER',
			"INNER JOIN b_user BP_USER ON BP_USER.ID = {$this->getAliasFieldName('USER_ID')}",
			"LEFT JOIN b_user BP_USER ON BP_USER.ID = {$this->getAliasFieldName('USER_ID')}"
		);

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new StringField('WORKFLOW_TEMPLATE'))
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
			(new StringField('MODULE_ID')),
			(new StringField('ENTITY')),
			(new StringField('DOCUMENT_TYPE')),
			(new StringField('DOCUMENT_STATUS')),
			(new StringField('NAME')),
			(new DateTimeField('MODIFIED'))
				->setCallback(
					static function ($value): ?string {
						$modifiedTimeTimestamp = strtotime($value);

						return $modifiedTimeTimestamp && $modifiedTimeTimestamp > 0 ? (string)$value : null;
					}
				)
			,
			(new StringField('IS_MODIFIED'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('IS_MODIFIED')} = 'Y' THEN 'Y' ELSE 'N' END"
				)
			,
			(new IntegerField('USER_ID')),
			(new StringField('USER_NAME'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('USER_ID')} > 0 THEN
						concat_ws(
							' ',
							nullif({$userJoin->getJoinFieldName('NAME')}, ''),
							nullif({$userJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($userJoin)
			,
			(new StringField('USER'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('USER_ID')} > 0 THEN
						concat_ws(
							' ',
							{$helper->getConcatFunction("'['", $this->getAliasFieldName('USER_ID'), "']'")},
							nullif({$userJoin->getJoinFieldName('NAME')}, ''),
							nullif({$userJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($userJoin)
			,
			(new StringField('SYSTEM_CODE')),
			(new StringField('IS_SYSTEM'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('IS_SYSTEM')} = 'Y' THEN 'Y' ELSE 'N' END"
				)
			,
			(new StringField('ACTIVE'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('ACTIVE')} = 'Y' THEN 'Y' ELSE 'N' END"
				)
			,
			(new StringField('TYPE')),
		];
	}

	protected function getFilter(): ?DatasetFilter
	{
		return new DatasetFilter(
			[
				'=TYPE' => \Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType::Default->value,
				'=IS_SYSTEM' => 'N',
			],
			[
				new StringField('TYPE'),
				new StringField('IS_SYSTEM'),
			]
		);
	}
}
