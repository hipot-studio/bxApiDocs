<?php

namespace Bitrix\Main\Cli\Command\Main\Module;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Application;
use Bitrix\Main\ModuleManager;
use ErrorException;
use Throwable;
use UnexpectedValueException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends AbstractFormattedCommand
{
	public function __construct(OutputFormatter $outputFormatter)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
			$this
				->setName('main:module:list')
				->setDescription('List modules available in current installation')
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		$installedModuleIds = $this->getInstalledModuleIds();
		$diskModuleIds = $this->getDiskModuleIds();
		$moduleIds = array_fill_keys([...$installedModuleIds, ...$diskModuleIds], true);
		ksort($moduleIds, SORT_STRING);
		$installedModules = array_fill_keys($installedModuleIds, true);
		$diskModules = array_fill_keys($diskModuleIds, true);
		$modules = [];
		foreach (array_keys($moduleIds) as $moduleId)
		{
			$record = [
				'id' => $moduleId,
				'installed' => isset($installedModules[$moduleId]),
				'available' => isset($diskModules[$moduleId]),
				'name' => null,
				'description' => null,
				'version' => null,
				'versionDate' => null,
				'sort' => null,
				'partner' => null,
				'partnerUri' => null,
				'error' => null,
			];
			if ($record['available'])
			{
				try
				{
					$record = array_replace($record, $this->loadDiskModuleMetadata($moduleId));
				}
				catch (Throwable)
				{
					$record['error'] = [
						'code' => 'metadata_unavailable',
						'message' => 'Failed to read module metadata',
					];
				}
			}
			$modules[] = $record;
		}

		return $this->renderSuccess(
			$output,
			$modules,
			columns: [
					'id' => 'ID',
					'installed' => 'Installed',
					'available' => 'Available',
					'name' => 'Name',
					'version' => 'Version',
			],
		);
	}

	protected function getInstalledModuleIds(): array
	{
		return array_keys(ModuleManager::getInstalledModules());
	}

	protected function getDiskModuleIds(): array
	{
		$moduleIds = [];
		foreach (['/bitrix/modules', '/local/modules'] as $modulesDirectory)
		{
			$path = Application::getDocumentRoot() . $modulesDirectory;
			$handle = is_readable($path) ? opendir($path) : false;
			if ($handle === false)
			{
				continue;
			}
			while (($moduleId = readdir($handle)) !== false)
			{
				if (
					$moduleId !== '.'
					&& $moduleId !== '..'
					&& ModuleManager::isValidModule($moduleId)
					&& is_file($path . '/' . $moduleId . '/install/index.php')
				)
				{
					$moduleIds[$moduleId] = true;
				}
			}
			closedir($handle);
		}

		return array_keys($moduleIds);
	}

	protected function loadDiskModuleMetadata(string $moduleId): array
	{
		set_error_handler(
			static function (int $severity, string $message, string $file, int $line): never
			{
				throw new ErrorException($message, 0, $severity, $file, $line);
			},
		);
		try
		{
			$module = \CModule::CreateModuleObject($moduleId);
			if (!is_object($module) || ($module->MODULE_ID ?? null) !== $moduleId)
			{
				throw new UnexpectedValueException('Invalid module metadata.');
			}

			return [
				'name' => self::nullableString($module->MODULE_NAME ?? null),
				'description' => self::nullableString($module->MODULE_DESCRIPTION ?? null),
				'version' => self::nullableString($module->MODULE_VERSION ?? null),
				'versionDate' => self::nullableString($module->MODULE_VERSION_DATE ?? null),
				'sort' => isset($module->MODULE_SORT) ? (int)$module->MODULE_SORT : null,
				'partner' => self::nullableString($module->PARTNER_NAME ?? null),
				'partnerUri' => self::nullableString($module->PARTNER_URI ?? null),
			];
		}
		finally
		{
			restore_error_handler();
		}
	}

	private static function nullableString(mixed $value): ?string
	{
		return $value === null || $value === '' ? null : (string)$value;
	}
}
