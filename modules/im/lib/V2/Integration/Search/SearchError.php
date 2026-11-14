<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Logger;
use Bitrix\Main\Localization\Loc;

/**
 * Error contract of the search facade (API-01).
 *
 * An empty output is a successful answer with an empty list, never an error; a failure of the search
 * path, on the contrary, never answers with a silent empty output.
 */
class SearchError extends Error
{
	public const
		QUERY_TOO_SHORT = 'SEARCH_QUERY_TOO_SHORT',
		QUERY_TOO_LONG = 'SEARCH_QUERY_TOO_LONG',
		WRONG_SORT = 'SEARCH_WRONG_SORT',
		SERVICE_UNAVAILABLE = 'SEARCH_SERVICE_UNAVAILABLE',
		FEATURE_UNAVAILABLE = 'FEATURE_UNAVAILABLE'
	;

	private const LOG_TAG = 'message-search-facade';

	public function __construct(string $code, ...$args)
	{
		parent::__construct($code, ...$args);

		// without this 'nobody searches' and 'the search refuses everyone' look the same from im
		if ($code === self::SERVICE_UNAVAILABLE)
		{
			(new Logger(self::LOG_TAG))->log($code . ': ' . $this->getMessage());
		}
	}

	protected function loadErrorMessage($code, $replacements): string
	{
		return Loc::getMessage("ERROR_{$code}", $replacements) ?: '';
	}

	protected function loadErrorDescription($code, $replacements): string
	{
		return Loc::getMessage("ERROR_{$code}_DESC", $replacements) ?: '';
	}
}
