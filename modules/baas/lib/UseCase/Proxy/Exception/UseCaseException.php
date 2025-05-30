<?php

declare(strict_types=1);

namespace Bitrix\Baas\UseCase\Proxy\Exception;

use \Bitrix\Baas;

abstract class UseCaseException extends Baas\UseCase\BaasException
{
	public function getCustomData(): ?array
	{
		return null;
	}
}
