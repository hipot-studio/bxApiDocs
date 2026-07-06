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
	 * @return $this|self
	 * @throws Exception
	 */
	public function add(string $stepperClass): self
	{
		if (!$this->context->getDatabaseUpdateMode()->canUpdateDatabase())
		{
			return $this;
		}

		if (!$this->context->moduleTablesExist())
		{
			return $this;
		}

		return $this->doAdd($stepperClass);
	}

	/**
	 * Add stepper $stepperClass. Checks if canUpdateDatabase and isModuleInstalled
	 * @param string $stepperClass
	 * @return $this|self
	 * @throws Exception
	 */
	public function addIfModuleInstalled(string $stepperClass): self
	{
		if (!$this->context->getDatabaseUpdateMode()->canUpdateDatabase())
		{
			return $this;
		}

		if (!$this->context->isModuleInstalled())
		{
			return $this;
		}

		return $this->doAdd($stepperClass);
	}

	/**
	 * Add stepper $stepperClass. Checks if canUpdateDatabase and moduleTablesExist
	 * @param string $stepperClass
	 * @return $this|self
	 * @throws Exception
	 */
	public function addIfModuleExists(string $stepperClass): self
	{
		return $this->add($stepperClass);
	}

	private function doAdd(string $stepperClass): self
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

		$delay = $this->context->isCloud() ? 600 : 0;

		$stepper = $this->stepperClass;
		$stepper::bindClass($stepperClass, $this->context->getModuleId(), $delay);

		return $this;
	}
}
