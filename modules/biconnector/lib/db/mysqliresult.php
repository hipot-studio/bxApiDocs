<?php
namespace Bitrix\BIConnector\DB;

use Bitrix\Main\DB\SqlQueryException;

class MysqliResult extends \Bitrix\Main\DB\MysqliResult
{
	protected $rowData = null;
	protected $rowDataReference = null;

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
		/** @var \mysqli_stmt $stmt */
		$stmt = $this->resource;
		if (!$this->rowData)
		{
			if ($stmt->execute() === false)
			{
				throw new SqlQueryException('Mysql query error', $stmt->error);
			}

			$c = $stmt->field_count;
			$this->rowData = [];
			$this->rowDataReference = [];
			for ($i = 0; $i < $c; $i++)
			{
				$this->rowData[] = '';
				$this->rowDataReference[] = &$this->rowData[count($this->rowData) - 1];
			}

			call_user_func_array([ $stmt, 'bind_result'], $this->rowDataReference);
		}

		$fetchResult = $stmt->fetch();
		if ($fetchResult === false)
		{
			throw new SqlQueryException('Mysql fetch error', $stmt->error);
		}

		if ($fetchResult === null)
		{
			return false;
		}

		$row = [];
		foreach ($this->rowData as $v)
		{
			$row[] = $v; //dereference
		}

		return $row;
	}
}
