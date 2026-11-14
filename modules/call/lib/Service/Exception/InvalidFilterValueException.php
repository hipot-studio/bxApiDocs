<?php

declare(strict_types=1);

namespace Bitrix\Call\Service\Exception;

use Bitrix\Main\ArgumentException;

/**
 * Raised by CallLogService::buildQueryFilter() when a filter key carries a value it cannot use:
 * TYPE and SEARCH take a scalar, STATUS a status of the journal or a list of them, EXCLUDE_STATUS
 * one status of the journal, both in any case ({@see \Bitrix\Call\Service\CallLogService::STATUSES}).
 * The offending key travels in the parameter of the argument exception, so a consumer can name it.
 */
class InvalidFilterValueException extends ArgumentException
{
	public function __construct(string $filterKey)
	{
		parent::__construct("Invalid value of the filter key {$filterKey}", $filterKey);
	}
}
