<?php declare(strict_types=1);

namespace Bitrix\AI\Limiter\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Connection;
use Bitrix\Main\DB\SqlHelper;
use Bitrix\Main\SystemException;

abstract class BaseRepository
{
	private SqlHelper $sqlHelper;
	private Connection $connection;

	abstract public function getTableName(): string;

	abstract public function getFieldEnum(): string;

	public function __get(string $name): string
	{
		$enumClass = $this->getFieldEnum();
		$fieldEnum = $enumClass::tryFrom($name);
		if (is_null($fieldEnum))
		{
			throw new SystemException(
				"No found field $name in {$this->getTableName()} Error in " . __CLASS__
			);
		}

		return $fieldEnum->value;
	}

	protected function getConnection(): Connection
	{
		if (empty($this->connection))
		{
			$this->connection = Application::getConnection();
		}

		return $this->connection;
	}

	protected function getSqlHelper()
	{
		if (empty($this->sqlHelper))
		{
			$this->sqlHelper = $this->getConnection()->getSqlHelper();
		}

		return $this->sqlHelper;
	}
}
