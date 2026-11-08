<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Wire error codes of the aggregation protocol (design §2.3).
 */
final class ErrorCode
{
	public const UNSUPPORTED = 'AGGREGATION_UNSUPPORTED';
	public const UNSUPPORTED_VERSION = 'AGGREGATION_UNSUPPORTED_VERSION';
	public const BAD_REQUEST = 'AGGREGATION_BAD_REQUEST';
	public const UNSUPPORTED_FIELD = 'AGGREGATION_UNSUPPORTED_FIELD';
	public const TYPE_MISMATCH = 'AGGREGATION_TYPE_MISMATCH';

	private function __construct()
	{
	}
}
