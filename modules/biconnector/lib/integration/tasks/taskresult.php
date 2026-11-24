<?php

namespace Bitrix\BIConnector\Integration\Tasks;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;
use Bitrix\Tasks\Internals\Task\Result\ResultTable;

class TaskResult extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'TASK_RESULT_FIELD_';

	protected function getResultTableName(): string
	{
		return 'task_result';
	}

	public function getSqlTableAlias(): string
	{
		return 'TR';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_tasks_result';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('TASK_RESULT_TABLE');
	}

	protected function getTableDescriptionFull(): string
	{
		return $this->getMessage('TASK_RESULT_TABLE_DESCRIPTION_FULL', '');
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

		$authorJoin = $this->createJoin(
			"U",
			"INNER JOIN b_user U ON U.ID = {$this->getAliasFieldName('CREATED_BY')}",
			"LEFT JOIN b_user U ON U.ID = {$this->getAliasFieldName('CREATED_BY')}",
		);

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new IntegerField('TASK_ID')),
			(new StringField('TEXT')),
			(new StringField('STATUS'))
				->setDictionary([
					ResultTable::STATUS_OPENED => $this->getMessage('TASK_RESULT_FIELD_STATUS_VALUE_OPENED'),
					ResultTable::STATUS_CLOSED => $this->getMessage('TASK_RESULT_FIELD_STATUS_VALUE_CLOSED'),
				])
			,
			(new IntegerField('CREATED_BY_ID'))
				->setName($this->getAliasFieldName('CREATED_BY'))
			,
			(new StringField('CREATED_BY_NAME'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('CREATED_BY')} > 0 THEN
						concat_ws(
							' ',
							nullif({$authorJoin->getJoinFieldName('NAME')}, ''),
							nullif({$authorJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($authorJoin)
			,
			(new StringField('CREATED_BY'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('CREATED_BY')} > 0 THEN
						concat_ws(
							' ',
							{$helper->getConcatFunction("'['", $this->getAliasFieldName('CREATED_BY'), "']'")},
							nullif({$authorJoin->getJoinFieldName('NAME')}, ''),
							nullif({$authorJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($authorJoin)
			,
			(new DateTimeField('CREATED_DATE'))
				->setName($this->getAliasFieldName('CREATED_AT'))
			,
			(new DateTimeField('CHANGED_DATE'))
				->setName($this->getAliasFieldName('UPDATED_AT'))
			,
		];
	}
}
