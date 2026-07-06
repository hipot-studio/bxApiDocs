<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Ddl\DbType;
use Bitrix\Main\UpdateSystem\Migration\Tools\Database;
use Bitrix\Main\Web\Json;

class Context
{
	private ?bool $moduleTablesExist = null;
	private ?array $migrationConfig = null;

	public function __construct(
		private readonly Config $config,
	)
	{
	}

	public function getModuleId(): string
	{
		return $this->config->getModuleId();
	}

	public function getUpdaterFilename(): string
	{
		return $this->config->getUpdaterFilename();
	}

	public function getUpdaterRootDirectory(): string
	{
		return $this->config->getUpdaterRootDirectory();
	}

	public function getKernelDirectory(): string
	{
		return defined('US_SHARED_KERNEL_PATH') ? US_SHARED_KERNEL_PATH : '/bitrix';
	}

	public function getDatabaseUpdateMode(): DatabaseUpdateMode
	{
		return $this->config->getDatabaseUpdateMode();
	}

	public function canUpdateDatabase(): bool
	{
		return $this->getDatabaseUpdateMode()->canUpdateDatabase();
	}

	public function canUpdateKernel(): bool
	{
		return $this->config->canUpdateKernel();
	}

	public function canUpdatePersonalFiles(): bool
	{
		return $this->config->canUpdatePersonalFiles();
	}

	public function isModuleInstalled(): bool
	{
		if ($this->getModuleId() === 'main')
		{
			return true;
		}

		return \Bitrix\Main\ModuleManager::isModuleInstalled($this->getModuleId());
	}

	public function isModuleExists(): bool
	{
		return $this->moduleTablesExist();
	}

	public function getDbType(): DbType
	{
		return DbType::getByConnectionType(Application::getConnection()->getType());
	}

	public function isDevMode(): bool
	{
		return $this->config->isDevMode();
	}

	public function isEtalon(): bool
	{
		return $this->isCloud()
			&& defined('BX24_IS_ETALON')
			&& BX24_IS_ETALON === true;
	}

	public function isStage(): bool
	{
		return $this->isCloud()
			&& defined('BX24_IS_STAGE')
			&& BX24_IS_STAGE === true;
	}

	public function isBox(): bool
	{
		return !$this->isCloud();
	}

	public function isCloud(): bool
	{
		return \Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24');
	}

	public function tableExists(string $tableName): bool
	{
		if ($this->getDatabaseUpdateMode() === DatabaseUpdateMode::PreliminaryGeneration)
		{
			return false;
		}

		return Database::tableExists($tableName);
	}

	public function columnExists(string $tableName, string $columnName): bool
	{
		if ($this->getDatabaseUpdateMode() === DatabaseUpdateMode::PreliminaryGeneration)
		{
			return false;
		}

		return Database::columnExists($tableName, $columnName);
	}

	public function indexExists(string $tableName, array $indexFields): bool
	{
		if ($this->getDatabaseUpdateMode() === DatabaseUpdateMode::PreliminaryGeneration)
		{
			return false;
		}

		return Database::indexExists($tableName, $indexFields);
	}

	public function getIndexName(string $tableName, array $indexFields): ?string
	{
		if ($this->getDatabaseUpdateMode() === DatabaseUpdateMode::PreliminaryGeneration)
		{
			return null;
		}

		return Database::getIndexName($tableName, $indexFields);
	}

	/**
	 * @return string[] Ordered list of columns that form the current primary key,
	 *  or [] if the table has no primary key or doesn't exist.
	 */
	public function getPrimaryKeyColumns(string $tableName): array
	{
		if ($this->getDatabaseUpdateMode() === DatabaseUpdateMode::PreliminaryGeneration)
		{
			return [];
		}

		return Database::getPrimaryKeyColumns($tableName);
	}

	public function isMySql(): bool
	{
		return $this->getDbType() === DbType::MySql;
	}

	public function isPostgreSql(): bool
	{
		return $this->getDbType() === DbType::PostgreSql;
	}

	public function moduleTablesExist(?string $ignoredTableName = null): bool
	{
		if ($this->isModuleInstalled())
		{
			return true;
		}

		if (is_bool($this->moduleTablesExist))
		{
			return $this->moduleTablesExist;
		}

		$defaultTableName = $this->getModuleDefaultTableName();
		if (empty($defaultTableName))
		{
			$this->moduleTablesExist = false;
		}
		elseif ($defaultTableName === $ignoredTableName)
		{
			$this->moduleTablesExist = true; // not check Database::tableExists to be able to create first module table in migration
		}
		elseif ($this->tableExists($defaultTableName))
		{
			$this->moduleTablesExist = true;
		}
		else
		{
			$this->moduleTablesExist = false;
		}

		return $this->moduleTablesExist;
	}

	private function getModuleDefaultTableName(): ?string
	{
		try
		{
			$migrationConfig = $this->getMigrationConfigFileContent();
		}
		catch (Exception $e)
		{
			if ($this->isDevMode())
			{
				throw $e;
			}

			return null;
		}

		if (empty($migrationConfig['defaultTableName']) && $this->isDevMode())
		{
			throw new Exception(
				$this->getModuleId(),
				101,
				'migration_config.json does not contain defaultTableName. Unable to determine default module table name',
				[
					'absoluteFilename' => $this->getMigrationInModuleConfigFilename(),
				],
			);
		}

		return $migrationConfig['defaultTableName'] ?? null;
	}

	private function getMigrationConfigFileContent(): array
	{
		if (is_array($this->migrationConfig))
		{
			return $this->migrationConfig;
		}

		$this->migrationConfig = [];

		$migrationConfigFileCandidates = [
			$_SERVER['DOCUMENT_ROOT'] . $this->config->getUpdaterRootDirectory() . '/migration_config.json',
			$this->getMigrationInModuleConfigFilename(),
		];

		foreach ($migrationConfigFileCandidates as $migrationConfigFileCandidate)
		{
			if (file_exists($migrationConfigFileCandidate))
			{
				try
				{
					$this->migrationConfig = Json::decode(file_get_contents($migrationConfigFileCandidate));
					if (!is_array($this->migrationConfig))
					{
						$this->migrationConfig = [];
					}
				}
				catch (ArgumentException)
				{
				}

				return $this->migrationConfig;
			}
		}

		throw new Exception(
			$this->getModuleId(),
			100,
			'migration_config.json not found. Unable to determine default module table name',
			[
				'absoluteFilename' => $this->getMigrationInModuleConfigFilename(),
			],
		);
	}

	private function getMigrationInModuleConfigFilename(): string
	{
		return $_SERVER['DOCUMENT_ROOT'] . $this->getKernelDirectory() . '/modules/' . $this->config->getModuleId() . '/migration_config.json';
	}
}
