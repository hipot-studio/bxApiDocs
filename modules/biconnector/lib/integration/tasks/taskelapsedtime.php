<?php

namespace Bitrix\BIConnector\Integration\Tasks;

use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;
use CTaskElapsedItem;

class TaskElapsedTime extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'TASK_ELAPSED_TIME_FIELD_';

	protected function getResultTableName(): string
	{
		return 'task_elapsed_time';
	}

	public function getSqlTableAlias(): string
	{
		return 'TET';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_tasks_elapsed_time';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('TASK_ELAPSED_TIME_TABLE');
	}

	protected function getTableDescriptionFull(): string
	{
		return $this->getMessage('TASK_ELAPSED_TIME_TABLE_DESCRIPTION_FULL_MSGVER_2', '');
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
			"INNER JOIN b_user U ON U.ID = {$this->getAliasFieldName('USER_ID')}",
			"LEFT JOIN b_user U ON U.ID = {$this->getAliasFieldName('USER_ID')}",
		);

		$entrySource = (new StringField('ENTRY_SOURCE', null, $this))
			->setDictionary([
				CTaskElapsedItem::SOURCE_UNDEFINED
					=> $this->getMessage('TASK_ELAPSED_TIME_FIELD_ENTRY_SOURCE_VALUE_UNDEFINED'),
				CTaskElapsedItem::SOURCE_MANUAL
					=> $this->getMessage('TASK_ELAPSED_TIME_FIELD_ENTRY_SOURCE_VALUE_MANUAL'),
				CTaskElapsedItem::SOURCE_SYSTEM
					=> $this->getMessage('TASK_ELAPSED_TIME_FIELD_ENTRY_SOURCE_VALUE_SYSTEM'),
			])
		;
		$entrySource->setName($entrySource->mapDictionaryToSqlCase($this->getAliasFieldName('SOURCE')));

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new IntegerField('TASK_ID')),
			(new IntegerField('USER_ID')),
			(new StringField('USER_NAME'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('USER_ID')} > 0 THEN
						concat_ws(
							' ',
							nullif({$authorJoin->getJoinFieldName('NAME')}, ''),
							nullif({$authorJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($authorJoin)
			,
			(new StringField('USER'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('USER_ID')} > 0 THEN
						concat_ws(
							' ',
							{$helper->getConcatFunction("'['", $this->getAliasFieldName('USER_ID'), "']'")},
							nullif({$authorJoin->getJoinFieldName('NAME')}, ''),
							nullif({$authorJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($authorJoin)
			,
			(new DateTimeField('DATE_START'))
				->setName($this->getAliasFieldName('CREATED_DATE'))
				->setDescription($this->getMessage('TASK_ELAPSED_TIME_FIELD_DATE_START_MSGVER_1'))
				->setDescriptionFull($this->getMessage('TASK_ELAPSED_TIME_FIELD_DATE_START_FULL_MSGVER_1'))
			,
			(new StringField('COMMENT_TEXT')),
			(new IntegerField('ELAPSED_TIME'))
				->setName($this->getAliasFieldName('SECONDS'))
			,
			(new DateTimeField('WORK_START_DATE'))
				->setName($this->getAliasFieldName('DATE_START'))
			,
			(new DateTimeField('WORK_END_DATE'))
				->setName($this->getAliasFieldName('DATE_STOP'))
			,
			$entrySource,
		];
	}
}
