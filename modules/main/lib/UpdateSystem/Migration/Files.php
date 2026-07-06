<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

class Files
{
	private readonly string $updateSystemClass;

	public function __construct(
		private readonly Context $context,
		?string $updateSystemClass = null,
	)
	{
		$this->updateSystemClass = $updateSystemClass ?? \CUpdateSystem::class;
	}

	/**
	 * Deletes files and directories. Paths should be relative, for example 'modules/main/lib/SomeFile.php'.
	 * @param array $dirsOrFilesToDelete - array of dirs or files to delete
	 * @return self
	 */
	public function delete(array $dirsOrFilesToDelete): self
	{
		if ($this->context->canUpdateKernel())
		{
			$kernelPath = $this->context->getKernelDirectory();

			$updateSystem = $this->updateSystemClass;
			foreach ($dirsOrFilesToDelete as $fileName)
			{
				$updateSystem::deleteDirFilesEx($_SERVER['DOCUMENT_ROOT'] . $kernelPath . '/' . $fileName);
			}
		}

		return $this;
	}

	/**
	 * Copy install files and directories. Paths should be relative, for example 'install/components'.
	 * @param string $fromDir - source dir
	 * @param string $toDir - destination dir into bitrix/
	 * @return self
	 */
	public function copyInstallDirectory(string $fromDir, string $toDir): self
	{
		if (!$this->context->canUpdateKernel())
		{
			return $this;
		}

		if (!$this->context->isModuleInstalled())
		{
			return $this;
		}

		$this->updateSystemClass::AddMessage2Log("Run updater '" . $this->context->getUpdaterFilename() . "': CopyFiles(" . $fromDir . ", " . $toDir . ")", 'CRUPDCF1');


		$fromDir = $this->context->getUpdaterRootDirectory() . '/' . $fromDir;
		$toDir = $this->context->getKernelDirectory() . '/' . $toDir;
		$this->copyDirectory($fromDir, $toDir);

		return $this;
	}

	/**
	 * Copy public files and directories. Paths should be relative, for example 'install/components'.
	 * @param string $fromDir - source dir
	 * @param string $toDir - destination dir into document root
	 * @return self
	 */
	public function copyPublicDirectory(string $fromDir, string $toDir): self
	{
		if (!$this->context->canUpdatePersonalFiles())
		{
			return $this;
		}

		if (!$this->context->isModuleInstalled())
		{
			return $this;
		}

		$this->updateSystemClass::AddMessage2Log("Run updater '" . $this->context->getUpdaterFilename() . "': CopyFiles(" . $fromDir . ", " . $toDir . ")", 'CRUPDCF1');

		$fromDir = $this->context->getUpdaterRootDirectory() . '/' . $fromDir;
		$this->copyDirectory($fromDir, $toDir);

		return $this;
	}

	private function copyDirectory(string $fromDir, string $toDir): void
	{
		$fromDirFull = $_SERVER['DOCUMENT_ROOT'] . $fromDir;
		$toDirFull = $_SERVER['DOCUMENT_ROOT'] . $toDir;
		if (!file_exists($fromDirFull))
		{
			return;
		}

		$errorMessage = '';
		$result = $this->updateSystemClass::CopyDirFiles($fromDirFull, $toDirFull, $errorMessage);

		if (!$result)
		{
			\CUpdater::addError($errorMessage);
		}
	}
}
