<?php

declare(strict_types=1);

namespace Bitrix\Crm\Reservation\Validator;

use Bitrix\Crm\Reservation\Error\BaseProductErrorAssembler;

abstract class BaseValidator implements ValidatorInterface
{
	protected BaseProductErrorAssembler $errorAssembler;

	public function __construct()
	{
		$this->initErrorAssembler();
	}

	public function __destruct()
	{
		unset(
			$this->errorAssembler,
		);
	}

	protected function getErrorAssembler(): BaseProductErrorAssembler
	{
		return $this->errorAssembler;
	}

	protected function initErrorAssembler(): void
	{
		$this->errorAssembler = new BaseProductErrorAssembler();
	}
}
