<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

use Bitrix\Main\SystemException;

/**
 * Carries a wire error code of the aggregation protocol.
 *
 * Only the code reaches the client; the detail stays on the portal side and is meant for logs.
 */
class AggregationException extends SystemException
{
	public function __construct(
		private readonly string $errorCode,
		string $detail = '',
	)
	{
		parent::__construct($detail === '' ? $errorCode : $errorCode . ': ' . $detail);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	public static function badRequest(string $detail): self
	{
		return new self(ErrorCode::BAD_REQUEST, $detail);
	}

	public static function typeMismatch(string $detail): self
	{
		return new self(ErrorCode::TYPE_MISMATCH, $detail);
	}

	public static function unsupportedField(string $detail): self
	{
		return new self(ErrorCode::UNSUPPORTED_FIELD, $detail);
	}

	public static function unsupported(string $detail = ''): self
	{
		return new self(ErrorCode::UNSUPPORTED, $detail);
	}
}
