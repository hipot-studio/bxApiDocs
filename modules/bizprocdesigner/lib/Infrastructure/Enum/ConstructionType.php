<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Enum;

enum ConstructionType: string
{
	case IF_CONDITION = 'condition:if';
	case AND_CONDITION = 'condition:and';
	case OR_CONDITION = 'condition:or';

	case ACTION = 'action';
	case OUTPUT = 'output';

	public function isCondition(): bool
	{
		return in_array($this, [
			self::IF_CONDITION,
			self::AND_CONDITION,
			self::OR_CONDITION,
		], true);
	}
}
