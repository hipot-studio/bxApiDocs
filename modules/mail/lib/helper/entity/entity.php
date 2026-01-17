<?php

namespace Bitrix\Mail\Helper\Entity;

abstract class Entity
{
	protected string $type;

	abstract public function toArray(): array;
	abstract public function getUniqueKeyValue(): string;
}
