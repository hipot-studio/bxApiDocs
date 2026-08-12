<?php

namespace Bitrix\BIConnector\Integration\Imopenlines;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;

class Session extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'IMOPENLINES_SESSION_FIELD_';

	protected function getResultTableName(): string
	{
		return 'imopenlines_session';
	}

	public function getSqlTableAlias(): string
	{
		return 'S';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_imopenlines_session';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('IMOPENLINES_SESSION_TABLE');
	}

	/**
	 * @return Result
	 */
	protected function onBeforeEvent(): Result
	{
		$result = parent::onBeforeEvent();

		if (!Loader::includeModule('imopenlines'))
		{
			$result->addError(new Error('Module is not installed'));
		}

		return $result;
	}

	protected function getFields(): array
	{
		/** @var \Bitrix\Main\DB\SqlHelper&\Bitrix\BIConnector\DB\BiSqlHelperInterface $helper */
		$helper = $this->getSqlHelper();

		$clientJoin = $this->createJoin(
			"UC",
			"INNER JOIN b_user UC ON UC.ID = {$this->getAliasFieldName('USER_ID')}",
			"LEFT JOIN b_user UC ON UC.ID = {$this->getAliasFieldName('USER_ID')}",
		);

		$operatorJoin = $this->createJoin(
			"UO",
			"INNER JOIN b_user UO ON UO.ID = {$this->getAliasFieldName('OPERATOR_ID')}",
			"LEFT JOIN b_user UO ON UO.ID = {$this->getAliasFieldName('OPERATOR_ID')}",
		);

		$configJoin = $this->createJoin(
			"CFG",
			"INNER JOIN b_imopenlines_config CFG ON CFG.ID = {$this->getAliasFieldName('CONFIG_ID')}",
			"LEFT JOIN b_imopenlines_config CFG ON CFG.ID = {$this->getAliasFieldName('CONFIG_ID')}",
		);

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new StringField('MODE'))
				->setDictionary([
					'input' => $this->getMessage('IMOPENLINES_SESSION_FIELD_MODE_VALUE_INPUT'),
					'output' => $this->getMessage('IMOPENLINES_SESSION_FIELD_MODE_VALUE_OUTPUT'),
				])
			,
			(new StringField('STATUS'))
				->setDictionary([
					\Bitrix\Imopenlines\Session::STATUS_NEW => $this->getMessage('IMOPENLINES_SESSION_FIELD_STATUS_VALUE_NEW'),
					\Bitrix\Imopenlines\Session::STATUS_SKIP => $this->getMessage('IMOPENLINES_SESSION_FIELD_STATUS_VALUE_SKIP'),
					\Bitrix\Imopenlines\Session::STATUS_ANSWER => $this->getMessage('IMOPENLINES_SESSION_FIELD_STATUS_VALUE_ANSWER'),
					\Bitrix\Imopenlines\Session::STATUS_CLIENT => $this->getMessage('IMOPENLINES_SESSION_FIELD_STATUS_VALUE_CLIENT'),
					\Bitrix\Imopenlines\Session::STATUS_CLIENT_AFTER_OPERATOR => $this->getMessage('IMOPENLINES_SESSION_FIELD_STATUS_VALUE_CLIENT_AFTER_OPERATOR'),
					\Bitrix\Imopenlines\Session::STATUS_OPERATOR => $this->getMessage('IMOPENLINES_SESSION_FIELD_STATUS_VALUE_OPERATOR'),
					\Bitrix\Imopenlines\Session::STATUS_WAIT_CLIENT => $this->getMessage('IMOPENLINES_SESSION_FIELD_STATUS_VALUE_WAIT_CLIENT'),
					\Bitrix\Imopenlines\Session::STATUS_CLOSE => $this->getMessage('IMOPENLINES_SESSION_FIELD_STATUS_VALUE_CLOSE'),
					\Bitrix\Imopenlines\Session::STATUS_SPAM => $this->getMessage('IMOPENLINES_SESSION_FIELD_STATUS_VALUE_SPAM'),
					\Bitrix\Imopenlines\Session::STATUS_DUPLICATE => $this->getMessage('IMOPENLINES_SESSION_FIELD_STATUS_VALUE_DUPLICATE'),
					\Bitrix\Imopenlines\Session::STATUS_SILENTLY_CLOSE => $this->getMessage('IMOPENLINES_SESSION_FIELD_STATUS_VALUE_SILENTLY_CLOSE'),
				])
			,
			(new StringField('SPAM')),
			(new StringField('SOURCE')),
			(new IntegerField('CONFIG_ID')),
			(new StringField('CONFIG_NAME'))
				->setName($configJoin->getJoinFieldName('LINE_NAME'))
				->setJoin($configJoin)
			,
			(new StringField('CONFIG'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('CONFIG_ID')} > 0 THEN
						concat_ws(
							' ',
							{$helper->getConcatFunction("'['", $this->getAliasFieldName('CONFIG_ID'), "']'")},
							nullif({$configJoin->getJoinFieldName('LINE_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($configJoin)
			,
			(new IntegerField('CLIENT_ID'))
				->setName($this->getAliasFieldName('USER_ID'))
			,
			(new StringField('CLIENT_NAME'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('USER_ID')} > 0 THEN
						concat_ws(
							' ',
							nullif({$clientJoin->getJoinFieldName('NAME')}, ''),
							nullif({$clientJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($clientJoin)
			,
			(new StringField('CLIENT'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('USER_ID')} > 0 THEN
						concat_ws(
							' ',
							{$helper->getConcatFunction("'['", $this->getAliasFieldName('USER_ID'), "']'")},
							nullif({$clientJoin->getJoinFieldName('NAME')}, ''),
							nullif({$clientJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($clientJoin)
			,
			(new IntegerField('OPERATOR_ID')),
			(new StringField('OPERATOR_NAME'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('OPERATOR_ID')} > 0 THEN
						concat_ws(
							' ',
							nullif({$operatorJoin->getJoinFieldName('NAME')}, ''),
							nullif({$operatorJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($operatorJoin)
			,
			(new StringField('OPERATOR'))
				->setName("
					CASE WHEN {$this->getAliasFieldName('OPERATOR_ID')} > 0 THEN
						concat_ws(
							' ',
							{$helper->getConcatFunction("'['", $this->getAliasFieldName('OPERATOR_ID'), "']'")},
							nullif({$operatorJoin->getJoinFieldName('NAME')}, ''),
							nullif({$operatorJoin->getJoinFieldName('LAST_NAME')}, '')
						)
					ELSE NULL END"
				)
				->setJoin($operatorJoin)
			,
			(new DateTimeField('DATE_CREATE')),
			(new DateTimeField('DATE_OPERATOR_ANSWER')),
			(new DateTimeField('DATE_OPERATOR_CLOSE')),
			(new StringField('WAIT_ANSWER')),
			(new IntegerField('TIME_ANSWER'))
				->setMetric()
			,
			(new IntegerField('TIME_CLOSE'))
				->setMetric()
			,
			(new IntegerField('TIME_DIALOG'))
				->setMetric()
			,
			(new IntegerField('VOTE')),
			(new IntegerField('TIME_FIRST_ANSWER'))
				->setMetric()
			,
			(new DateTimeField('DATE_LAST_MESSAGE')),
			(new DateTimeField('DATE_CLOSE')),
		];
	}
}
