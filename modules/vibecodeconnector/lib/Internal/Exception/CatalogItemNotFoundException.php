<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Exception;

use Bitrix\Main\ObjectNotFoundException;

final class CatalogItemNotFoundException extends ObjectNotFoundException
{
	public function __construct(
		public readonly int $catalogItemId,
		?\Throwable $previous = null,
	)
	{
		parent::__construct("Catalog item {$catalogItemId} not found", $previous);
	}
}
