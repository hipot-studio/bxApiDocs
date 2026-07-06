<?php

declare(strict_types = 1);

namespace Bitrix\Main\UpdateSystem\Migration;

class Config
{
	private bool $devMode = false;
	private DatabaseUpdateMode $databaseUpdateMode = DatabaseUpdateMode::Disabled;
	private string $updaterRootDirectory;
	private ?string $defaultTableName = null;

	public function __construct(
		private readonly string $moduleId,
		private readonly string $updaterFilename,
		private readonly bool $canUpdateKernel,
		private readonly bool $canUpdatePersonalFiles,
	)
	{
		$this->updaterRootDirectory = dirname($updaterFilename);
	}

	public function getModuleId(): string
	{
		return $this->moduleId;
	}

	public function getUpdaterFilename(): string
	{
		return $this->updaterFilename;
	}

	public function canUpdateKernel(): bool
	{
		return $this->canUpdateKernel;
	}

	public function canUpdatePersonalFiles(): bool
	{
		return $this->canUpdatePersonalFiles;
	}

	public function getUpdaterRootDirectory(): string
	{
		return $this->updaterRootDirectory;
	}

	public function setUpdaterRootDirectory(string $updaterRootDirectory): self
	{
		$this->updaterRootDirectory = $updaterRootDirectory;

		return $this;
	}

	public function isDevMode(): bool
	{
		return $this->devMode;
	}

	public function setDevMode(bool $devMode = true): self
	{
		$this->devMode = $devMode;

		return $this;
	}

	public function getDatabaseUpdateMode(): DatabaseUpdateMode
	{
		return $this->databaseUpdateMode;
	}

	public function setDatabaseUpdateMode(DatabaseUpdateMode $mode): self
	{
		$this->databaseUpdateMode = $mode;

		return $this;
	}
}
