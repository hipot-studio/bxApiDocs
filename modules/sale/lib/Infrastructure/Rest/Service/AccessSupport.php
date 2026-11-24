<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Single place holding the access rules of the module for the Rest V3 layer.
 *
 * The rules repeat V1 verbatim: reading needs the `sale` module right other than denied,
 * writing needs it not lower than write. Order ownership is not checked, as in V1.
 */
final class AccessSupport
{
	private const RIGHT_DENIED = 'D';
	private const RIGHT_WRITE = 'W';

	/** Reading an order. Parity with Controller::checkReadPermissionEntity(). */
	public function ensureOrderRead(): Result
	{
		return $this->ensureModuleRight(fn(string $right): bool => $right !== self::RIGHT_DENIED);
	}

	/** Writing an order, including the exchange methods. Parity with Controller::checkModifyPermissionEntity(). */
	public function ensureOrderModify(): Result
	{
		return $this->ensureModuleRight(fn(string $right): bool => $right >= self::RIGHT_WRITE);
	}

	/**
	 * @param callable(string): bool $isAllowed
	 */
	private function ensureModuleRight(callable $isAllowed): Result
	{
		$result = new Result();
		if (!$isAllowed($this->getSaleModuleRight()))
		{
			$result->addError(new Error('Access denied', RestErrorCode::ACCESS_DENIED));
		}

		return $result;
	}

	private function getSaleModuleRight(): string
	{
		global $APPLICATION;

		return (string)$APPLICATION->GetGroupRight('sale');
	}
}
