<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget;

use Bitrix\Intranet;

abstract class BaseContent implements \JsonSerializable
{
	public function __construct(protected readonly Intranet\User $user)
	{
	}

	abstract public function getName(): string;

	abstract public function getConfiguration(): array;

	public function jsonSerialize(): array
	{
		return $this->getConfiguration();
	}
}
