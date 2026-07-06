<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration\Tools;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Ddl\DbType;
use Bitrix\Main\UpdateSystem\Migration as MigrationFacade;
use Bitrix\Main\UpdateSystem\Migration\Config;
use Bitrix\Main\UpdateSystem\Migration\ConfigFactory;
use Bitrix\Main\UpdateSystem\Migration\Context;
use Bitrix\Main\UpdateSystem\Migration\DatabaseUpdateMode;
use Bitrix\Main\UpdateSystem\Migration\Table;

/**
 * Collects SQL queries produced by a migration file without executing them
 * against the live database. Used by callers that need to inspect what a
 * migration would emit
 */
class MigrationQueryCollector
{
	public function __construct(
		private readonly string $absoluteMigrationFileName,
		private readonly string $moduleId,
		private readonly DatabaseUpdateMode $mode,
		private readonly DbType $dbType = DbType::MySql,
	)
	{
	}

	/**
	 * @return string[] Captured SQL queries in execution order.
	 */
	public function collect(): array
	{
		if (!is_file($this->absoluteMigrationFileName))
		{
			throw new ArgumentException(
				'Migration file not found: ' . $this->absoluteMigrationFileName,
				'absoluteMigrationFile',
			);
		}

		$config = (new Config(
			moduleId: $this->moduleId,
			updaterFilename: $this->absoluteMigrationFileName,
			canUpdateKernel: false,
			canUpdatePersonalFiles: false,
		))->setDatabaseUpdateMode($this->mode);

		$context = new IsolatedMigrationContext($config, $this->dbType);
		$migration = $this->buildSpyMigration($config, $context);
		$instancesProp = self::getMigrationInstancesProperty();

		if ($this->mode === DatabaseUpdateMode::SiteChecker)
		{
			// SiteChecker collects tables.php — a pure declarative migration file
			// that does not reference $updater. No CUpdater setup is needed, and
			// because site_checker.php drives query collection itself (one
			// migration file at a time, then exits), we don't bother restoring
			// the previous Migration::$instances / ConfigFactory state either.
			$instancesProp->setValue(null, [$this->absoluteMigrationFileName => $migration]);
			ConfigFactory::setDefaultConfig($config);

			(static function (string $migrationFile): void
			{
				require $migrationFile;
			})($this->absoluteMigrationFileName);

			return $migration->getCapturedQueries();
		}

		$previousInstances = $instancesProp->getValue(null);
		$previousConfig = ConfigFactory::getConfig();

		$instancesProp->setValue(null, [$this->absoluteMigrationFileName => $migration]);
		ConfigFactory::setDefaultConfig($config);

		$dbTypeStr = match ($this->dbType)
		{
			DbType::MySql => 'mysql',
			DbType::PostgreSql => 'pgsql',
		};

		try
		{
			(static function (string $migrationFile, string $dbType, string $moduleId): void
			{
				/** @noinspection PhpUnusedLocalVariableInspection */
				global $DB, $APPLICATION, $USER;

				$strongUpdateCheck = false;
				$DOCUMENT_ROOT = $_SERVER['DOCUMENT_ROOT'];

				$path = str_replace('\\', '/', $migrationFile);
				$updaterPath = dirname($path);
				$updaterPath = substr($updaterPath, strlen($_SERVER['DOCUMENT_ROOT']));
				$updaterPath = trim($updaterPath, " \t\n\r\0\x0B/\\");
				if ($updaterPath !== '')
				{
					$updaterPath = '/' . $updaterPath;
				}
				$updaterName = substr($path, strlen($_SERVER['DOCUMENT_ROOT']));

				$updater = new \CUpdater();
				$updater->Init($updaterPath, $dbType, $updaterName, $updaterPath, $moduleId, []);

				require $migrationFile;
			})($this->absoluteMigrationFileName, $dbTypeStr, $this->moduleId);
		}
		finally
		{
			$instancesProp->setValue(null, $previousInstances);
			if ($previousConfig !== null)
			{
				ConfigFactory::setDefaultConfig($previousConfig);
			}
			else
			{
				ConfigFactory::clearDefaultConfig();
			}
		}

		return $migration->getCapturedQueries();
	}

	private function buildSpyMigration(Config $config, Context $context): SpyMigration
	{
		$migrationClass = new \ReflectionClass(MigrationFacade::class);
		$spyClass = new \ReflectionClass(SpyMigration::class);

		// Migration has a private constructor — bypass it and seed the parent's
		// private fields ($config, $context) via reflection.
		/** @var SpyMigration $migration */
		$migration = $spyClass->newInstanceWithoutConstructor();

		$configProp = $migrationClass->getProperty('config');
		$configProp->setValue($migration, $config);

		$contextProp = $migrationClass->getProperty('context');
		$contextProp->setValue($migration, $context);

		return $migration;
	}

	private static function getMigrationInstancesProperty(): \ReflectionProperty
	{
		return (new \ReflectionClass(MigrationFacade::class))->getProperty('instances');
	}
}

/**
 * Migration subclass that hands out {@see SpyTable} instances and accumulates
 * the SQL captured from each table.
 *
 * @internal Used by {@see MigrationQueryCollector}.
 */
class SpyMigration extends MigrationFacade
{
	/** @var SpyTable[] */
	private array $spyTables = [];

	/** @var string[] */
	private array $capturedQueries = [];

	public function table(string $tableName): Table
	{
		if (!isset($this->spyTables[$tableName]))
		{
			$this->spyTables[$tableName] = new SpyTable($tableName, $this->context(), $this);
		}

		return $this->spyTables[$tableName];
	}

	public function recordQuery(string $sql): void
	{
		$this->capturedQueries[] = $sql;
	}

	/**
	 * @return string[]
	 */
	public function getCapturedQueries(): array
	{
		return $this->capturedQueries;
	}
}

/**
 * Table subclass that captures emitted SQL into the owning {@see SpyMigration}
 * instead of executing it against the real database.
 *
 * @internal Used by {@see MigrationQueryCollector}.
 */
class SpyTable extends Table
{
	public function __construct(
		string $tableName,
		Context $context,
		private readonly SpyMigration $spy,
	)
	{
		parent::__construct($tableName, $context);
	}

	protected function executeQuery(string $sql): void
	{
		$this->spy->recordQuery($sql);
	}
}

/**
 * Stub Context with the minimum overrides required for offline query collection:
 *   - {@see self::isModuleInstalled()} returns true so {@see Context::moduleTablesExist()}
 *     returns true without calling {@see \Bitrix\Main\ModuleManager} (which would
 *     hit the real DB).
 *   - {@see self::getDbType()} returns the explicitly configured type instead of
 *     deriving it from the live database connection.
 *
 * The actual mode flag (PreliminaryGeneration / SiteChecker / etc.) lives on
 * the wrapped {@see Config} — closures inside Migration helpers branch on
 * `usesRealDatabase()` and `isPreliminary()` to decide rendering behaviour.
 *
 * @internal Used by {@see MigrationQueryCollector}.
 */
class IsolatedMigrationContext extends Context
{
	public function __construct(
		Config $config,
		private readonly DbType $dbType,
	)
	{
		parent::__construct($config);
	}

	public function isModuleInstalled(): bool
	{
		return true;
	}

	public function getDbType(): DbType
	{
		return $this->dbType;
	}
}
