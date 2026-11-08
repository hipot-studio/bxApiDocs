<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\IncomingWebhook;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;

final class ExpirationChecker
{
	private const DATE_FORMAT = 'Y-m-d';

	public function __construct(
		private readonly ?Date $currentDate = null,
	)
	{
	}

	public function isExpired(array|IncomingWebhook $webhook): bool
	{
		$dateExpire = $this->getDateExpire($webhook);
		if ($dateExpire === null)
		{
			return false;
		}

		return $dateExpire < $this->getCurrentDate();
	}

	public function getCurrentDate(): Date
	{
		return $this->currentDate ?? new Date();
	}

	private function getDateExpire(array|IncomingWebhook $webhook): ?Date
	{
		if ($webhook instanceof IncomingWebhook)
		{
			return $webhook->getDateExpire();
		}

		return $this->normalizeDate($webhook['DATE_EXPIRE'] ?? null);
	}

	private function normalizeDate(mixed $date): ?Date
	{
		if ($date instanceof Date)
		{
			return $date;
		}

		if ($date instanceof DateTime)
		{
			return new Date($date->format(self::DATE_FORMAT), self::DATE_FORMAT);
		}

		if (is_string($date) && $date !== '')
		{
			try
			{
				return new Date($date, self::DATE_FORMAT);
			}
			catch (\Throwable)
			{
				return null;
			}
		}

		return null;
	}
}
