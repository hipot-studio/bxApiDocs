<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Providers;

abstract class Provider implements ProviderInterface
{
	abstract public function getData(): ?array;
}