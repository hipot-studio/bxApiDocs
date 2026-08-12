<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\Message;

use Bitrix\Mail\Internal\Service\DateTime\DateTimeParser;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class MailStatisticsDto
{
	public function __construct(
		public readonly ?int $mailboxId = null,
		public readonly ?int $employeeId = null,
		public readonly ?DateTime $dateFrom = null,
		public readonly ?DateTime $dateTo = null,
	)
	{
	}

	/**
	 * @throws SystemException
	 */
	public static function fromArray(array $props): self
	{
		$mailboxId = self::getInt($props, 'mailboxId');
		$employeeId = self::getInt($props, 'employeeId');
		if ($mailboxId !== null && $employeeId !== null)
		{
			throw new SystemException('Use either mailboxId or employeeId, not both.');
		}

		$dateFrom = DateTimeParser::getNullableLowerBound($props, 'dateFrom');
		$dateTo = DateTimeParser::getNullableUpperBound($props, 'dateTo');
		DateTimeParser::validateRange($dateFrom, $dateTo);

		return new self(
			mailboxId: $mailboxId,
			employeeId: $employeeId,
			dateFrom: $dateFrom,
			dateTo: $dateTo,
		);
	}

	private static function getInt(array $props, string $key): ?int
	{
		if (!isset($props[$key]) || !is_numeric($props[$key]))
		{
			return null;
		}

		return (int)$props[$key];
	}

}
