<?php

namespace Bitrix\Crm\Agent\History;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\History\Entity\DealStageHistoryTable;
use Bitrix\Crm\History\Entity\DealStageHistoryWithSupposedTable;
use Bitrix\Crm\History\Entity\EntityStageHistoryTable;
use Bitrix\Crm\History\Entity\EntityStageHistoryWithSupposedTable;
use Bitrix\Crm\History\Entity\LeadStatusHistoryTable;
use Bitrix\Crm\History\Entity\LeadStatusHistoryWithSupposedTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Psr\Log\LoggerInterface;

final class CleanWrongHistoryOwnerIdAgent extends AgentBase
{
	private const MODULE = 'crm';
	private const DEFAULT_LIMIT_VALUE = 500;
	private const OPTION_TABLE = 'clean_wrong_history_owner_id_option_table';
	/**
	 * @var array|string[]
	 */
	private array $tables;
	private ?string $table;
	private LoggerInterface $logger;

	public function __construct()
	{
		$this->tables = [
			DealStageHistoryTable::class,
			DealStageHistoryWithSupposedTable::class,
			EntityStageHistoryTable::class,
			EntityStageHistoryWithSupposedTable::class,
			LeadStatusHistoryTable::class,
			LeadStatusHistoryWithSupposedTable::class,
		];
		$this->table = Option::get(self::MODULE, self::OPTION_TABLE, $this->tables[0]);
		$this->logger = Container::getInstance()->getLogger('Default');
	}

	public static function doRun(): bool
	{
		return (new self())->execute();
	}

	private function execute(): bool
	{
		$zeroOwnerIds = $this->getZeroOwnerIds();

		if (empty($zeroOwnerIds))
		{
			$nextTable = $this->getNextTable();
			if (!$nextTable)
			{
				$this->cleanAfterWork();

				return false;
			}

			$this->table = $nextTable;

			Option::set(self::MODULE, self::OPTION_TABLE, $this->table);

			return true;
		}

		call_user_func([$this->table, 'deleteByFilter'], ['@ID' => $zeroOwnerIds]);

		return true;
	}

	private function getZeroOwnerIds(): array
	{
		/** @var Query $query */
		$query = call_user_func([$this->table, 'query'], []);

		return $query
			->setSelect(['ID'])
			->where('OWNER_ID', 0)
			->setLimit(self::DEFAULT_LIMIT_VALUE)
			->fetchCollection()
			?->getIdList() ?? []
		;
	}

	private function getNextTable(): ?string
	{
		$currentTableKey = array_search($this->table, $this->tables, true);

		if ($currentTableKey === false)
		{
			$this->logger->info('CleanWrongHistoryOwnerIdAgent: Next not found', [
				'wrongTableClass' => $this->table,
			]);

			return null;
		}

		return $this->tables[$currentTableKey + 1] ?? null;
	}

	private function cleanAfterWork(): void
	{
		\COption::RemoveOption(self::MODULE, self::OPTION_TABLE);

		$this->logger->info('CleanWrongHistoryOwnerIdAgent: work completed');
	}
}
