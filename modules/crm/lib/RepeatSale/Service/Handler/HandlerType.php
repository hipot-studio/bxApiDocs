<?php

namespace Bitrix\Crm\RepeatSale\Service\Handler;

use Bitrix\Main\ArgumentException;

enum HandlerType: int
{
	case SystemHandler = 1;
	case ConfigurableHandler = 2;
	case AiScreeningHandler = 3;
	case AiApproveHandler = 4;
	case RemainingHandler = 5;

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

		if ($value === self::RemainingHandler->value)
		{
			return self::RemainingHandler;
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
			self::RemainingHandler->value,
		];
	}

	public static function isAiHandler(self $type): bool
	{
		$aiHandlers = [
			self::AiScreeningHandler,
			self::AiApproveHandler,
			self::RemainingHandler,
		];

		return in_array($type, $aiHandlers, true);
	}
}
