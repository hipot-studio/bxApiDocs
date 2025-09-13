<?php

declare(strict_types=1);

namespace Bitrix\Tasks\DI\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Inject
{
	public function __construct(
		public readonly ?string $locatorCode = null,
		public readonly ?string $externalModule = null
	)
	{

	}
}