<?php

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Starter\Enum\Face;
use Bitrix\Main\ModuleManager;

final class Context
{
	public readonly string $moduleId;
	public readonly Face $face;

	private bool $isManual = false;

	public function __construct(string $moduleId, Face $face)
	{
		$this->moduleId = $moduleId;
		$this->face = $face;
	}

	public function setIsManual(): self
	{
		$this->isManual = true;
		return $this;
	}

	public function isManualOperation(): bool
	{
		return $this->isManual;
	}
}
