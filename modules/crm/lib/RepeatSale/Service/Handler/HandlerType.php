<?php

namespace Bitrix\Crm\RepeatSale\Service\Handler;

use Bitrix\Main\ArgumentException;

enum HandlerType: int
{
	case SystemHandler = 1;
	case ConfigurableHandler = 2;
	case AiScreeningHandler = 3;
	case AiApproveHandler = 4;

	public static function fromValue(int $value): self
	{
		if ($value === self::SystemHandler->value)
		{
			return self::SystemHandler;
		}

		if ($value === self::ConfigurableHandler->value)
		{
			return self::ConfigurableHandler;
		}

		if ($value === self::AiScreeningHandler->value)
		{
			return self::AiScreeningHandler;
		}

		if ($value === self::AiApproveHandler->value)
		{
			return self::AiApproveHandler;
		}

		throw new ArgumentException('Unknown HandlerType value: ' . $value, 'value');
	}

	public static function getValues(): array
	{
		return [
			self::SystemHandler->value,
			self::ConfigurableHandler->value,
			self::AiScreeningHandler->value,
			self::AiApproveHandler->value,
		];
	}
}
