<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

class Module
{
	private readonly string $cModuleClass;

	public function __construct(
		private readonly Context $context,
		?string $cModuleClass = null,
	)
	{
		$this->cModuleClass = $cModuleClass ?? \CModule::class;
	}

	public function install(): void
	{
		if (!$this->context->isModuleInstalled())
		{
			$cModule = $this->cModuleClass;
			$moduleInstaller = $cModule::CreateModuleObject($this->context->getModuleId());

			if ($moduleInstaller)
			{
				if ($this->context->getDatabaseUpdateMode()->canUpdateDatabase())
				{
					$moduleInstaller->installDB();
				}

				if ($this->context->canUpdateKernel())
				{
					$moduleInstaller->InstallFiles();
				}
			}
		}
	}
}
