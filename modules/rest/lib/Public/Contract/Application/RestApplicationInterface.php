<?php

declare(strict_types=1);


namespace Bitrix\Rest\Public\Contract\Application;

interface RestApplicationInterface
{
	public function getClientId(): ?string;

	public function getClientSecret(): ?string;
}
