<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance;

use Bitrix\Main\Application;
use Bitrix\Vibecodeconnector\Internal\Entity\Balance\BalanceValue;
use Bitrix\Vibecodeconnector\Internal\Entity\Balance\VibeBalanceAlert;

final class BalanceAlertResolver
{
	private const HIGH_THRESHOLD_REGIONS = ['ru', 'by', 'kz', 'uz'];

	public function __construct(private readonly ?string $region = null)
	{
	}

	public function resolve(?string $balance): VibeBalanceAlert
	{
		if ($balance === null)
		{
			return VibeBalanceAlert::None;
		}

		$value = new BalanceValue($balance);
		$zero = new BalanceValue('0');
		if ($zero->isGreaterThan($value))
		{
			return VibeBalanceAlert::Negative;
		}

		if (!$value->isGreaterThan($zero))
		{
			return VibeBalanceAlert::Low;
		}

		$region = strtolower($this->region ?? Application::getInstance()->getLicense()->getRegion() ?? '');
		$threshold = in_array($region, self::HIGH_THRESHOLD_REGIONS, true) ? '500' : '5';

		return (new BalanceValue($threshold))->isGreaterThan($value) ? VibeBalanceAlert::Low : VibeBalanceAlert::None;
	}
}
