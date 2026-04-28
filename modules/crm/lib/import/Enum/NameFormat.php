<?php

namespace Bitrix\Crm\Import\Enum;

use Bitrix\Crm\Format\PersonNameFormatter;
use Bitrix\Crm\Import\Contract\Enum\HasTitleInterface;

enum NameFormat: int implements HasTitleInterface
{
	case Default = 1;
	case HonorificLast = 6;
	case FirstLast = 2;
	case FirstSecondLast = 3;
	case LastFirst = 4;
	case LastFirstSecond = 5;

	public function getTitle(): ?string
	{
		return PersonNameFormatter::getAllDescriptions()[$this->value] ?? null;
	}
}
