<?php

namespace Bitrix\Sign\Type;

class B2eErrorCode
{
	public const EXPIRED = 'expired';
	public const REQUEST_ERROR = 'request_error';
	public const SNILS_NOT_FOUND = 'snils_not_found';
	public const DOCUMENT_PREPARATION_FAILED = 'document_preparation_failed';
	public const DOCUMENT_PREPARATION_UNAVAILABLE = 'document_preparation_unavailable';
	public const SIGNING_FAILED = 'signing_failed';

	public static function isDocumentPreparationFailure(string $code): bool
	{
		return in_array(
			$code,
			[self::DOCUMENT_PREPARATION_FAILED, self::DOCUMENT_PREPARATION_UNAVAILABLE],
			true,
		);
	}

	/**
	 * @return array<self::*>
	 */
	public static function getAll(): array
	{
		return [
			self::EXPIRED,
			self::REQUEST_ERROR,
			self::SNILS_NOT_FOUND,
			self::DOCUMENT_PREPARATION_FAILED,
			self::DOCUMENT_PREPARATION_UNAVAILABLE,
			self::SIGNING_FAILED,
		];
	}
}
