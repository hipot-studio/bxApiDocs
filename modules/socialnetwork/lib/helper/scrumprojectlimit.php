<?php

namespace Bitrix\Socialnetwork\Helper;

use Bitrix\Main\Application;
use Bitrix\Main\Data\ConnectionPool;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Util\Restriction\Bitrix24Restriction\Limit\ScrumLimit;

/** The caller owns the transaction and keeps primary mode until its local commit or rollback. */
class ScrumProjectLimit
{
	private const MODULE_ID = 'socialnetwork';
	private const VERSION_OPTION = '~scrum_project_limit_version';
	private readonly Connection $connection;

	public function __construct(?Connection $connection = null)
	{
		$this->connection = $connection ?? Application::getConnection();
	}

	public function isProtectionRequired(): bool
	{
		return $this->hasRestrictionModules() && !$this->isTrialEnabled() && !$this->canTurnOnTrial();
	}

	/** Call when protection is required, inside the writer transaction, before any counter read or increase. */
	public function lock(): void
	{
		$this->onPrimary(function (): void {
			$select = $this->sql('SELECT VALUE FROM b_option WHERE MODULE_ID = ?s AND NAME = ?s');
			$currentRow = $this->connection->query($select . ' FOR UPDATE')->fetch();
			if ($currentRow === false)
			{
				$this->initializeVersion();
				$currentRow = $this->connection->query($select . ' FOR UPDATE')->fetch();
			}
			$current = $currentRow['VALUE'] ?? null;
			$snapshot = $this->connection->queryScalar($select);
			if ($current === null || $snapshot === null || (string)$current !== (string)$snapshot)
			{
				throw new ScrumProjectLimitException(ScrumProjectLimitException::STALE_SNAPSHOT);
			}
		});
	}

	/** Run only after the tariff check, immediately before the counted write, while holding the row lock. */
	public function incrementVersion(): void
	{
		$this->onPrimary(function (): void {
			$value = $this->connection->getSqlHelper()->castToChar('CAST(VALUE AS DECIMAL(20, 0)) + 1');
			$this->connection->queryExecute($this->sql(
				'UPDATE b_option SET VALUE = ' . $value . ' WHERE MODULE_ID = ?s AND NAME = ?s',
			));
		});
	}

	/** Provisioning and fallback never change an already existing version. */
	public function initializeVersion(): void
	{
		$this->onPrimary(function (): void {
			$this->connection->queryExecute($this->connection->getSqlHelper()->getInsertIgnore(
				'b_option',
				' (MODULE_ID, NAME, VALUE)',
				$this->sql(" SELECT ?s, ?s, '0'"),
			));
		});
	}

	protected function hasRestrictionModules(): bool
	{
		return Loader::includeModule('tasks') && Loader::includeModule('bitrix24');
	}

	protected function isTrialEnabled(): bool
	{
		return ScrumLimit::isFeatureEnabledByTrial();
	}

	protected function canTurnOnTrial(): bool
	{
		return ScrumLimit::canTurnOnTrial();
	}

	protected function getConnectionPool(): ConnectionPool
	{
		return Application::getInstance()->getConnectionPool();
	}

	private function onPrimary(callable $operation): void
	{
		$pool = $this->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$operation();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function sql(string $template): string
	{
		$sql = new SqlExpression($template, self::MODULE_ID, self::VERSION_OPTION);
		$sql->setConnection($this->connection);

		return $sql->compile();
	}
}
