<?php

declare(strict_types=1);


namespace Bitrix\Rest\Public\Contract\Application;

interface RestApplicationInterface
{
	public function getId(): ?int;

	public function getClientId(): ?string;

	public function getClientSecret(): ?string;

	public function getApplicationToken(): ?string;
}
