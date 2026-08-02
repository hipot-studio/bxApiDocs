<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\UpdateSystem\Migration\Tools\SourceCode;

class Stepper {
	private readonly string $stepperClass;

	public function __construct(
		private readonly Context $context,
		?string $stepperBinderClass = null,
	)
	{
		$this->stepperClass = $stepperBinderClass ?? \Bitrix\Main\Update\Stepper::class;
	}

	/**
	 * Add stepper $stepperClass. Checks if canUpdateDatabase and moduleTablesExist
	 * @param string $stepperClass
	 * @param int|null $execDelay First execution delay in seconds.
	 * @return $this|self
	 * @throws Exception
	 */
	public function add(string $stepperClass, ?int $execDelay = null): self
	{
		if (!$this->context->getDatabaseUpdateMode()->canUpdateDatabase())
		{
			return $this;
		}

		if (!$this->context->moduleTablesExist())
		{
			return $this;
		}

		return $this->doAdd($stepperClass, $execDelay);
	}

	/**
	 * Add stepper $stepperClass. Checks if canUpdateDatabase and isModuleInstalled
	 * @param string $stepperClass
	 * @param int|null $execDelay First execution delay in seconds.
	 * @return $this|self
	 * @throws Exception
	 */
	public function addIfModuleInstalled(string $stepperClass, ?int $execDelay = null): self
	{
		if (!$this->context->getDatabaseUpdateMode()->canUpdateDatabase())
		{
			return $this;
		}

		if (!$this->context->isModuleInstalled())
		{
			return $this;
		}

		return $this->doAdd($stepperClass, $execDelay);
	}

	/**
	 * Add stepper $stepperClass. Checks if canUpdateDatabase and moduleTablesExist
	 * @param string $stepperClass
	 * @param int|null $execDelay First execution delay in seconds.
	 * @return $this|self
	 * @throws Exception
	 */
	public function addIfModuleExists(string $stepperClass, ?int $execDelay = null): self
	{
		return $this->add($stepperClass, $execDelay);
	}

	private function doAdd(string $stepperClass, ?int $execDelay = null): self
	{
		if (
			$this->context->isDevMode()
			&& str_starts_with($stepperClass, '\\')
		)
		{
			throw new Exception(
				$this->context->getModuleId(),
				202,
				'Stepper class should not start with backslash',
			);
		}
		if (
			$this->context->isDevMode()
			&& SourceCode::classBelongsToModule($stepperClass, $this->context->getModuleId()) === false
		)
		{
			throw new Exception(
				$this->context->getModuleId(),
				203,
				'Stepper class should belong to module',
			);
		}

		$delay = 60;

		if ($this->context->isCloud())
		{
			$delay = 60 * 10;
		}
		elseif (defined('BX_PRODUCT_INSTALLATION') && BX_PRODUCT_INSTALLATION === true) // distr installation
		{
			$delay = 60 * 15;
		}
		elseif ($this->context->getDatabaseUpdateMode() === DatabaseUpdateMode::ModuleInstall)  // module installation from an updater
		{
			$delay = 60;
		}

		$delay = max($delay, $execDelay ?? 0);

		$stepper = $this->stepperClass;
		$stepper::bindClass($stepperClass, $this->context->getModuleId(), $delay);

		return $this;
	}
}
