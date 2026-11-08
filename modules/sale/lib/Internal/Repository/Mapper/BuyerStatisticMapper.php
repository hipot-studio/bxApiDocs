<?php

declare(strict_types=1);

namespace Bitrix\Sale\Internal\Repository\Mapper;

use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Public\Dto\BuyerStatistic;

final class BuyerStatisticMapper
{
	/**
	 * Prefix the columns of the statistic come under when the query is built over the buyers: the
	 * buyer record has an ID and a LID of its own.
	 */
	public const STATISTIC_PREFIX = 'STATISTIC_';

	/**
	 * @param array $row row of the statistic with the columns of the buyer record under their own names;
	 *   the buyer columns are empty when the record is gone, the statistic ones when the buyer has none
	 */
	public static function convertFromRow(array $row): BuyerStatistic
	{
		$statistic = static fn(string $key): mixed =>
			array_key_exists(self::STATISTIC_PREFIX . $key, $row) ? $row[self::STATISTIC_PREFIX . $key] : ($row[$key] ?? null)
		;

		return new BuyerStatistic(
			id: (int)$statistic('ID'),
			userId: (int)$row['USER_ID'],
			siteId: (string)$statistic('LID'),
			currency: (string)$statistic('CURRENCY'),
			sumPaid: (float)$statistic('SUM_PAID'),
			countFullPaidOrder: (int)$statistic('COUNT_FULL_PAID_ORDER'),
			countPartPaidOrder: (int)$statistic('COUNT_PART_PAID_ORDER'),
			lastOrderDate: self::convertDate($statistic('LAST_ORDER_DATE')),
			login: (string)($row['LOGIN'] ?? ''),
			name: (string)($row['NAME'] ?? ''),
			lastName: (string)($row['LAST_NAME'] ?? ''),
			secondName: (string)($row['SECOND_NAME'] ?? ''),
			email: (string)($row['EMAIL'] ?? ''),
			personalPhone: (string)($row['PERSONAL_PHONE'] ?? ''),
			lastLogin: self::convertDate($row['LAST_LOGIN'] ?? null),
			dateRegister: self::convertDate($row['DATE_REGISTER'] ?? null),
			externalAuthId: (string)($row['EXTERNAL_AUTH_ID'] ?? ''),
		);
	}

	private static function convertDate(mixed $value): ?DateTime
	{
		return $value instanceof DateTime ? $value : null;
	}
}
