<?php
namespace Bitrix\BIConnector\DB;

class PgsqlResult extends \Bitrix\Main\DB\PgsqlResult
{
	protected $rowData = null;
	protected $rowDataReference = null;

	/** @var int[]|false */
	protected $byteaFieldIndexes = false;

	/**
	 * Returns null because there is no way to know the fields.
	 *
	 * @return null
	 */
	public function getFields()
	{
		return null;
	}

	/**
	 * Returns next result row or false.
	 *
	 * @return array|false
	 */
	protected function fetchRowInternal()
	{
		if (!$this->resource)
		{
			return false;
		}

		$row = pg_fetch_row($this->resource);
		if ($row === false)
		{
			return false;
		}

		if ($this->byteaFieldIndexes === false)
		{
			$this->byteaFieldIndexes = [];

			$fieldsCount = pg_num_fields($this->resource);
			for ($i = 0; $i < $fieldsCount; $i++)
			{
				$fieldType = pg_field_type($this->resource, $i);
				if ($fieldType === 'bytea')
				{
					$this->byteaFieldIndexes[] = $i;
				}
			}
		}

		if ($this->byteaFieldIndexes)
		{
			foreach ($this->byteaFieldIndexes as $index)
			{
				if (array_key_exists($index, $row) && $row[$index] !== null)
				{
					$row[$index] = pg_unescape_bytea($row[$index]);
				}
			}
		}

		return $row;
	}
}
