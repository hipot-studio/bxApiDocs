<?php

namespace Bitrix\Crm\Agent\Entity\Recurring;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Config\Option;
use Psr\Log\LoggerInterface;

class ColumnAppendAgent extends AgentBase
{
	private const MODULE_ID = 'crm';
	private const OPTION_NAME = 'AddRecurringColumn';
	private const DEFAULT_LIMIT = 100;

	private const AGENT_DONE_STOP_IT = false;
	private const AGENT_CONTINUE = true;

	private LoggerInterface $logger;

	public static function doRun(): bool
	{
		return (new static())->execute();
	}

	private function __construct()
	{
		$this->logger = Container::getInstance()->getLogger('Agent');
	}

	private function execute(): bool
	{
		global $DB;

		$minId = $this->getMinId();
		$list = \Bitrix\Crm\Model\Dynamic\TypeTable::query()
			->setSelect(['ID', 'TABLE_NAME', 'ENTITY_TYPE_ID'])
			->setFilter([
				'>ID' => $minId,
			])
			->setLimit(self::DEFAULT_LIMIT)
			->addOrder('ID', 'ASC')
			->fetchAll()
		;

		if (empty($list))
		{
			return self::AGENT_DONE_STOP_IT;
		}

		foreach ($list as $type)
		{
			$needAnalyzeTable = false;

			$minId = (int)$type['ID'];
			$tableName = $DB->ForSql($type['TABLE_NAME']);
			$entityTypeId = (int)$type['ENTITY_TYPE_ID'];

			$logParams = [
				'tableName' => $type['TABLE_NAME'],
				'id' => $minId,
				'entityTypeId' => $entityTypeId,
			];

			if ($this->columnExists($type['TABLE_NAME'], 'IS_RECURRING'))
			{
				$this->logger->info('ColumnAppendBaseAgent: IS_RECURRING column exist', $logParams);
			}
			else
			{
				$DB->Query(
					"ALTER TABLE {$tableName} ADD COLUMN IS_RECURRING char(1) NOT NULL DEFAULT 'N';",
					true,
				);

				if ($this->columnExists($type['TABLE_NAME'], 'IS_RECURRING'))
				{
					$this->logger->info('ColumnAppendBaseAgent: IS_RECURRING column add', $logParams);

					$DB->Query("ALTER TABLE {$tableName} ALTER IS_RECURRING DROP DEFAULT;");

					$this->logger->info('ColumnAppendBaseAgent: IS_RECURRING column drop default', $logParams);

					$needAnalyzeTable = true;
				}
				else
				{
					$optionName = '~is_recurring_column_alter_success_' . $entityTypeId;
					\Bitrix\Main\Config\Option::set('crm', $optionName, 'N');

					$this->logger->info(
						'ColumnAppendBaseAgent: option set',
						array_merge($logParams, ['optionName' => $optionName]),
					);
				}

				if ($needAnalyzeTable)
				{
					if ($DB->type === 'PGSQL')
					{
						$DB->Query("ANALYZE {$tableName}");
					}
					else
					{
						$DB->Query("ANALYZE TABLE {$tableName}");
					}
				}
			}
		}

		$this->setMinId($minId);

		return self::AGENT_CONTINUE;
	}

	private function columnExists(string $tableName, string $columnName): bool
	{
		global $DB;

		$re = '/[^A-Za-z0-9\_]+/';
		$columnName = preg_replace($re, '', $columnName);
		$tableName = preg_replace($re, '', $tableName);
		if (empty($tableName) || empty($columnName))
		{
			return false;
		}

		 return $DB->Query("SELECT {$columnName} FROM {$tableName} WHERE 1 = 0", true) !== false;
	}

	private function getMinId(): int
	{
		return (int)Option::get(self::MODULE_ID, self::OPTION_NAME, 0);
	}

	private function setMinId(int $minId): void
	{
		Option::set(self::MODULE_ID,  self::OPTION_NAME, $minId);
	}

	private function deleteMinId(): void
	{
		Option::delete(self::MODULE_ID,  ['name' => self::OPTION_NAME]);
	}
}
