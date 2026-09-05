<?php
declare(strict_types=1);

namespace Bitrix\Landing\Metrika;

/**
 * Detects publication limit errors among the error codes of a failed publication.
 */
class PublicationErrorStatusMapper
{
	private const LIMIT_CODES = [
		'PUBLIC_SITE_REACHED',
		'PUBLIC_SITE_REACHED_FREE',
		'PUBLIC_PAGE_REACHED',
	];

	private const CODE_DELIMITER = '|';

	/**
	 * @param string $errorCode Single error code or several codes glued with the delimiter.
	 */
	public static function resolve(string $errorCode): Statuses
	{
		if ($errorCode === '')
		{
			return Statuses::ErrorB24;
		}

		foreach (explode(self::CODE_DELIMITER, $errorCode) as $code)
		{
			if (in_array(trim($code), self::LIMIT_CODES, true))
			{
				return Statuses::ErrorLimit;
			}
		}

		return Statuses::ErrorB24;
	}
}
