<?php

namespace Bitrix\BIConnector\Integration\Tasks;

use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class TaskHistory extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'TASK_HISTORY_FIELD_';

	private const FIELD_NAME_CODES = [
		'TITLE',
		'DESCRIPTION',
		'REAL_STATUS',
		'STATUS',
		'PRIORITY',
		'MARK',
		'COMMENT',
		'DELETE',
		'NEW',
		'RENEW',
		'MOVE_TO_BACKLOG',
		'MOVE_TO_SPRINT',
		'PARENT_ID',
		'GROUP_ID',
		'CREATED_BY',
		'RESPONSIBLE_ID',
		'ACCOMPLICES',
		'AUDITORS',
		'DEADLINE',
		'START_DATE_PLAN',
		'END_DATE_PLAN',
		'DURATION_PLAN',
		'DURATION_PLAN_SECONDS',
		'DURATION_FACT',
		'TIME_ESTIMATE',
		'TIME_SPENT_IN_LOGS',
		'TAGS',
		'DEPENDS_ON',
		'FILES',
		'UF_TASK_WEBDAV_FILES',
		'CHECKLIST_ITEM_CREATE',
		'CHECKLIST_ITEM_RENAME',
		'CHECKLIST_ITEM_REMOVE',
		'CHECKLIST_ITEM_CHECK',
		'CHECKLIST_ITEM_UNCHECK',
		'ADD_IN_REPORT',
		'TASK_CONTROL',
		'ALLOW_TIME_TRACKING',
		'ALLOW_CHANGE_DEADLINE',
		'FLOW_ID',
		'STAGE',
		'DELETED_FILES',
		'NEW_FILES',
		'UF_CRM_TASK_ADDED',
		'UF_CRM_TASK_DELETED',
		'UF_MAIL_MESSAGE',
		'RESULT',
		'RESULT_EDIT',
		'RESULT_REMOVE',
		'COMMENT_EDIT',
		'COMMENT_DEL',
		'CHECKLIST_ITEM_MAKE_IMPORTANT',
		'CHECKLIST_ITEM_MAKE_UNIMPORTANT',
		'COMMENT_REMOVE',
	];

	protected function getResultTableName(): string
	{
		return 'task_history';
	}

	public function getSqlTableAlias(): string
	{
		return 'TH';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_tasks_log';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('TASK_HISTORY_TABLE');
	}

	protected function getTableDescriptionFull(): string
	{
		return $this->getMessage('TASK_HISTORY_TABLE_DESCRIPTION_FULL', '');
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
			'U',
			"INNER JOIN b_user U ON U.ID = {$this->getAliasFieldName('USER_ID')}",
			"LEFT JOIN b_user U ON U.ID = {$this->getAliasFieldName('USER_ID')}",
		);

		$fieldName = new StringField('FIELD_NAME', null, $this);
		$fieldName->setDictionary($this->getFieldNameDictionary());
		$fieldName->setName($fieldName->mapDictionaryToSqlCase($this->getAliasFieldName('FIELD')));

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new IntegerField('TASK_ID')),
			(new DateTimeField('CREATED_DATE')),
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
			(new StringField('FIELD')),
			$fieldName,
			(new StringField('FROM_VALUE')),
			(new StringField('TO_VALUE')),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function getFieldNameDictionary(): array
	{
		$dictionary = [];
		foreach (self::FIELD_NAME_CODES as $code)
		{
			$dictionary[$code] = $this->getMessage(self::FIELD_NAME_PREFIX . 'FIELD_NAME_VALUE_' . $code);
		}

		return $dictionary;
	}
}
