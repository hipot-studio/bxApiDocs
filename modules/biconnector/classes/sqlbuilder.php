<?php

use Bitrix\BIConnector\Manager;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlHelper;

class CBIConnectorSqlBuilder extends CSQLWhere
{
	protected $select;

	protected ?Connection $connection = null;
	protected array $selectFieldNames = [];

	public function setConnection(Connection $connection): self
	{
		$this->connection = $connection;

		return $this;
	}

	/**
	 * @throws \Bitrix\Main\Config\ConfigurationException
	 */
	private function getSqlHelper(): SqlHelper
	{
		if (empty($this->connection))
		{
			$manager = Manager::getInstance();

			$this->connection = $manager->getDatabaseConnection();
		}

		return $this->connection->getSqlHelper();
	}

	public function SetSelect($selectedFields, $options = [])
	{
		$this->select = [];
		$sqlHelper = $this->getSqlHelper();
		$selectedTimezone = \Bitrix\BIConnector\Configuration\DataTimezone::getTimezoneOffset();

		foreach ($selectedFields as $fieldName => $fieldInfo)
		{
			$this->selectFieldNames[] = $fieldName;

			$formattedFieldName = $this->formatFieldExpression($fieldInfo, $options, $sqlHelper, $selectedTimezone);

			$this->select[] = sprintf('%s AS %s', $formattedFieldName, $sqlHelper->quote($fieldName));

			$this->c_joins[$fieldName] = ($this->c_joins[$fieldName] ?? 0) + 1;

			if (isset($fieldInfo['TABLE_ALIAS']))
			{
				$this->l_joins[$fieldInfo['TABLE_ALIAS']] = ($this->l_joins[$fieldInfo['TABLE_ALIAS']] ?? 0) + 1;
			}
		}
	}

	private function formatFieldExpression(array $fieldInfo, array $options, SqlHelper $sqlHelper, ?string $timezone): string
	{
		$fieldName = $fieldInfo['FIELD_NAME'];

		if (($fieldInfo['IS_FIELD_NAME_PREPARED'] ?? 'Y') === 'N')
		{
			$fieldName = $sqlHelper->quote($fieldName);
		}

		if ($fieldInfo['FIELD_TYPE'] === 'datetime' && isset($options['datetime_format']))
		{
			$fieldExpression = $this->applyTimezoneConversion($fieldName, $sqlHelper, $timezone);

			return $sqlHelper->formatDate($options['datetime_format'], $fieldExpression);
		}

		if ($fieldInfo['FIELD_TYPE'] === 'date' && isset($options['date_format']))
		{
			return $sqlHelper->formatDate($options['date_format'], $fieldName);
		}

		return $fieldName;
	}

	private function applyTimezoneConversion(string $fieldName, SqlHelper $sqlHelper, ?string $timezone): string
	{
		if ($timezone && $sqlHelper instanceof \Bitrix\BIConnector\DB\BiSqlHelperInterface)
		{
			return $sqlHelper->convertTimezone(
				$fieldName,
				$sqlHelper->getSessionTimezoneExpression(),
				$timezone
			);
		}

		return $fieldName;
	}

	public function GetSelect()
	{
		return implode("\n  ,", $this->select);
	}

	public function GetSelectFieldNames()
	{
		return $this->selectFieldNames;
	}

	public function GetJoins()
	{
		$result = [];

		foreach ($this->c_joins as $fieldName => $counter)
		{
			if ($counter > 0)
			{
				$TABLE_ALIAS = $this->fields[$fieldName]['TABLE_ALIAS'];
				if (isset($this->l_joins[$TABLE_ALIAS]) && $this->l_joins[$TABLE_ALIAS])
				{
					$resultJoin = $this->fields[$fieldName]['LEFT_JOIN'] ?? false;
				}
				else
				{
					$resultJoin = $this->fields[$fieldName]['JOIN'] ?? false;
				}

				if ($resultJoin)
				{
					if (is_array($resultJoin))
					{
						foreach ($resultJoin as $join)
						{
							$result[$join] = $join;
						}
					}
					else
					{
						$result[$resultJoin] = $resultJoin;
					}
				}
			}
		}

		if ($result)
		{
			return implode("\n  ", $result);
		}
		else
		{
			return '';
		}
	}
}
