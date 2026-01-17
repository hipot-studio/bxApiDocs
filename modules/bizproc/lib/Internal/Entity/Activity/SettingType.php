<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity;

class SettingType implements \Stringable
{
	public function __construct(
		public readonly string $name,
		public readonly string $description = '',
	) {}

	public function __toString(): string
	{
		return $this->name;
	}
}