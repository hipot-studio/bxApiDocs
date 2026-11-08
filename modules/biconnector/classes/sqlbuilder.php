<?php

use Bitrix\BIConnector\DB\BiAggregationDialectInterface;
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

			$formattedFieldName = self::formatFieldExpression($fieldInfo, $options, $sqlHelper, $selectedTimezone);

			$this->select[] = sprintf('%s AS %s', $formattedFieldName, $sqlHelper->quote($fieldName));

			$this->RegisterSelectedField($fieldName, $fieldInfo);
		}
	}

	/**
	 * Counts a field as selected so that GetJoins() emits the joins it needs.
	 *
	 * The aggregation path builds its own select list but relies on the same join bookkeeping, which
	 * is what keeps its row set equal to the one of the raw path.
	 */
	public function RegisterSelectedField(string $fieldName, array $fieldInfo): void
	{
		$this->c_joins[$fieldName] = ($this->c_joins[$fieldName] ?? 0) + 1;

		if (isset($fieldInfo['TABLE_ALIAS']))
		{
			$this->l_joins[$fieldInfo['TABLE_ALIAS']] = ($this->l_joins[$fieldInfo['TABLE_ALIAS']] ?? 0) + 1;
		}
	}

	/**
	 * The select expression of a field, exactly as the raw path builds it.
	 *
	 * The dialect is passed explicitly: on the aggregation path resolving the helper from the
	 * connection again would make the typing by BiAggregationDialectInterface merely declarative.
	 */
	public static function GetFieldExpression(
		array $fieldInfo,
		array $options,
		BiAggregationDialectInterface $dialect,
	): string
	{
		$fieldName = self::GetRawFieldExpression($fieldInfo, $dialect);
		$timezone = \Bitrix\BIConnector\Configuration\DataTimezone::getTimezoneOffset();

		if (self::AppliesTimezoneConversion($fieldInfo, $options))
		{
			$fieldExpression = $timezone
				? $dialect->convertTimezone($fieldName, $dialect->getSessionTimezoneExpression(), $timezone)
				: $fieldName
			;

			return $dialect->formatDateExpression($options['datetime_format'], $fieldExpression);
		}

		if (($fieldInfo['FIELD_TYPE'] ?? '') === 'date' && isset($options['date_format']))
		{
			return $dialect->formatDateExpression($options['date_format'], $fieldName);
		}

		return $fieldName;
	}

	/**
	 * The bare column reference of a field, with no date formatting or timezone conversion.
	 */
	public static function GetRawFieldExpression(array $fieldInfo, BiAggregationDialectInterface $dialect): string
	{
		$fieldName = $fieldInfo['FIELD_NAME'] ?? '';

		if (($fieldInfo['IS_FIELD_NAME_PREPARED'] ?? 'Y') === 'N')
		{
			return $dialect->quoteIdentifier($fieldName);
		}

		return $fieldName;
	}

	/**
	 * Whether the select expression of this field shifts it into the data timezone.
	 *
	 * The single place that decides it. A filter over the same field has to invert exactly this
	 * decision: the consumer re-checks the predicate against the value the select list produced, so a
	 * filter that shifts where the projection does not - or the other way round - silently returns a
	 * different set of rows. Deciding it twice, once here and once in the filter, is how these two drifted
	 * apart three times over.
	 */
	public static function AppliesTimezoneConversion(array $fieldInfo, array $options): bool
	{
		return ($fieldInfo['FIELD_TYPE'] ?? '') === 'datetime' && isset($options['datetime_format']);
	}

	private static function formatFieldExpression(
		array $fieldInfo,
		array $options,
		SqlHelper $sqlHelper,
		?string $timezone,
	): string
	{
		$fieldName = $fieldInfo['FIELD_NAME'] ?? '';

		if (($fieldInfo['IS_FIELD_NAME_PREPARED'] ?? 'Y') === 'N')
		{
			$fieldName = $sqlHelper->quote($fieldName);
		}

		if (self::AppliesTimezoneConversion($fieldInfo, $options))
		{
			$fieldExpression = self::applyTimezoneConversion($fieldName, $sqlHelper, $timezone);

			return $sqlHelper->formatDate($options['datetime_format'], $fieldExpression);
		}

		if (($fieldInfo['FIELD_TYPE'] ?? '') === 'date' && isset($options['date_format']))
		{
			return $sqlHelper->formatDate($options['date_format'], $fieldName);
		}

		return $fieldName;
	}

	private static function applyTimezoneConversion(string $fieldName, SqlHelper $sqlHelper, ?string $timezone): string
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
				// A field without a table alias needs no join, and neither JOIN nor LEFT_JOIN can be
				// present for it.
				$TABLE_ALIAS = $this->fields[$fieldName]['TABLE_ALIAS'] ?? null;
				if ($TABLE_ALIAS === null)
				{
					continue;
				}

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
