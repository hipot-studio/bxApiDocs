<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;

/**
 * @internal
 */
final class UnknownFormEntityException extends RuntimeException
{
	/**
	 * @param list<string> $entityNames the names the dictionary of fields knows nothing about
	 */
	public function __construct(public readonly array $entityNames)
	{
		parent::__construct('Unknown CRM entities: ' . implode(', ', $entityNames) . '.');
	}
}
