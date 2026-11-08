<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Public\Entity\LegalRegistry\ExpiryRule;
use DateTimeImmutable;
use DateTimeZone;

final class ExpiryCalculator
{
	public function calculate(ExpiryRule $rule, DateTimeImmutable $acceptedAt, string $timezone): ?DateTimeImmutable
	{
		if (!in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))
		{
			throw new \InvalidArgumentException('INVALID_PAYLOAD');
		}
		$zone = new DateTimeZone($timezone);
		$local = $acceptedAt->setTimezone($zone);
		if ($rule->kind === 'none') { return null; }
		if ($rule->kind === 'date')
		{
			$expires = (new DateTimeImmutable($rule->date . ' 00:00:00', $zone))->modify('+1 day');
		}
		elseif ($rule->unit === 'days')
		{
			if ($rule->amount > 3652059) { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
			$expires = $local->modify('+' . $rule->amount . ' days');
		}
		else
		{
			$year = (int)$local->format('Y');
			$month = (int)$local->format('n');
			$amount = $rule->amount;
			if ($amount > ($rule->unit === 'years' ? 9999 : 119988))
			{
				throw new \InvalidArgumentException('INVALID_PAYLOAD');
			}
			$months = ($year - 1) * 12 + $month - 1 + ($rule->unit === 'years' ? $amount * 12 : $amount);
			$year = intdiv($months, 12) + 1;
			$month = $months % 12 + 1;
			if ($year > 9999) { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
			$first = $local->setDate($year, $month, 1);
			$expires = $local->setDate($year, $month, min((int)$local->format('j'), (int)$first->format('t')));
		}
		if ((int)$expires->format('Y') < 1 || (int)$expires->format('Y') > 9999)
		{
			throw new \InvalidArgumentException('INVALID_PAYLOAD');
		}

		return $expires->setTimezone(new DateTimeZone('UTC'));
	}
}
