<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Update;

use Bitrix\Main\UpdateSystem\Migration;

class FilesMigration
{
	public static function run(array $params = []): void
	{
		$migration = Migration::getInstance();
		$context = $migration->context();
		if ($context->getModuleId() !== 'intranet')
		{
			return;
		}

		$documentRoot = $_SERVER['DOCUMENT_ROOT'];
		$updaterPath = $documentRoot . $context->getUpdaterRootDirectory();
		$kernelPath = $documentRoot . $context->getKernelDirectory();

		if ($context->canUpdateKernel())
		{
			self::syncKernel($migration, $params, $updaterPath, $kernelPath);
		}

		if ($context->canUpdatePersonalFiles())
		{
			if (
				is_dir($updaterPath . '/install/public/bitrix24')
				&& (is_dir($kernelPath . '/wizards/bitrix/bitrix24') || $context->isCloud())
				&& !is_dir($kernelPath . '/wizards/bitrix/portal')
			)
			{
				$migration->files()->copyPublicDirectory('install/public/bitrix24', '/');

				if (defined('BX_COMP_MANAGED_CACHE'))
				{
					$GLOBALS['CACHE_MANAGER']->clearByTag('bitrix24_left_menu');
				}
			}

			if (is_dir($updaterPath . '/install/public/pub'))
			{
				$migration->files()->copyPublicDirectory('install/public/pub', '/pub');
			}

			if (
				!empty($params['personal'])
				&& is_array($params['personal'])
				&& is_dir($kernelPath . '/wizards/bitrix/portal')
			)
			{
				self::syncPersonal($params['personal'], $kernelPath, $documentRoot);
			}
		}

		if ($context->canUpdateKernel())
		{
			foreach (['portal', 'portal_clear'] as $wizard)
			{
				$relativePath = 'install/wizards/bitrix/' . $wizard;
				$installedPath = 'modules/intranet/' . $relativePath;
				if (is_dir($kernelPath . '/' . $installedPath))
				{
					$migration->files()->delete([$installedPath]);
				}

				if (is_dir($updaterPath . '/' . $relativePath))
				{
					\CUpdateClient::DeleteDirFilesEx($updaterPath . '/' . $relativePath);
				}
			}
		}
	}

	private static function syncKernel(
		Migration $migration,
		array $params,
		string $updaterPath,
		string $kernelPath,
	): void
	{
		foreach (['wizards/bitrix', 'templates'] as $directory)
		{
			foreach (self::getSubdirs($updaterPath . '/install/' . $directory) as $item)
			{
				$fromPath = 'install/' . $directory . '/' . $item;
				$toPath = $directory . '/' . $item;
				if (!is_dir($updaterPath . '/' . $fromPath))
				{
					continue;
				}

				if (!is_dir($kernelPath . '/' . $toPath))
				{
					if (
						(empty($params[$fromPath]['new']) && empty($params['kernel'][$fromPath]['new']))
						|| file_exists($kernelPath . '/' . $toPath)
					)
					{
						continue;
					}
				}

				$migration->files()->copyInstallDirectory($fromPath, $toPath);
			}
		}
	}

	private static function syncPersonal(array $params, string $kernelPath, string $documentRoot): void
	{
		foreach (static::getSites() as $site)
		{
			$siteDir = (string)$site['DIR'] !== '' ? $site['DIR'] : '/';
			$siteRoot = (string)$site['DOC_ROOT'] !== '' ? $site['DOC_ROOT'] : $documentRoot;
			foreach ($params as $from => $to)
			{
				$fromPath = preg_replace('#^install/+#', '', trim($from, '/'));
				if (!str_starts_with($fromPath, 'wizards/bitrix/portal'))
				{
					continue;
				}

				$toPath = is_callable($to) ? $to($site, $from) : $to;
				if ($toPath === false || $toPath === null)
				{
					continue;
				}

				$fromPath = $kernelPath . '/' . trim($fromPath, '/');
				$toPath = $siteRoot . $siteDir . trim($toPath, '/');
				if (!file_exists($fromPath))
				{
					continue;
				}

				$error = '';
				\CUpdateClient::CopyDirFiles($fromPath, $toPath, $error);
				require_once $kernelPath . '/modules/main/classes/general/wizard_util.php';

				if (is_dir($toPath))
				{
					\CWizardUtil::ReplaceMacrosRecursive($toPath, ['SITE_DIR' => $siteDir]);
				}
				else
				{
					\CWizardUtil::ReplaceMacros($toPath, ['SITE_DIR' => $siteDir]);
				}
			}
		}
	}

	protected static function getSites(): array
	{
		return \CUtil::getSitesByWizard('portal');
	}

	private static function getSubdirs(string $path): array
	{
		$result = [];
		if (is_dir($path) && ($handle = @opendir($path)) !== false)
		{
			while (($item = readdir($handle)) !== false)
			{
				if ($item === '.' || $item === '..')
				{
					continue;
				}

				if (is_dir($path . '/' . $item))
				{
					$result[] = $item;
				}
			}
		}

		return $result;
	}
}
