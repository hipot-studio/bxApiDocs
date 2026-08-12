<?php

namespace Bitrix\BIConnector\Integration\Tasks;

use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;

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
		return $this->getMessage('TASK_ELAPSED_TIME_TABLE_DESCRIPTION_FULL', '');
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
			,
			(new StringField('COMMENT_TEXT'))
				->setCallback(
					static function($value) {
						if (empty($value))
						{
							return '';
						}

						return strlen($value) > 100 ? mb_substr($value, 0, 100) . '...' : $value;
					}
				)
			,
			(new IntegerField('ELAPSED_TIME'))
				->setName($this->getAliasFieldName('SECONDS'))
			,
		];
	}
}
