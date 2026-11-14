<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use RuntimeException;

/**
 * There is no manual expenses pack with the ID: a pack of the advertising integration is not found either.
 *
 * @internal
 */
final class ExpensesPackNotFoundException extends RuntimeException
{
	public function __construct(
		public readonly int $packId,
	)
	{
		parent::__construct(sprintf('Manual expenses pack `%d` was not found.', $packId));
	}
}
