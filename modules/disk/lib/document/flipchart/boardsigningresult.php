<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Disk\Document\Flipchart\Enum\BoardIndexErrorCategory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class BoardSigningResult extends Result
{
	private function __construct(
		public readonly string $token = '',
		public readonly string $apiUrl = '',
		public readonly ?BoardIndexErrorCategory $errorCategory = null,
		public readonly ?int $httpStatus = null,
	)
	{
		parent::__construct();
	}

	public static function createSuccess(string $token, string $apiUrl): self
	{
		return new self(token: $token, apiUrl: $apiUrl);
	}

	public static function createError(
		BoardIndexErrorCategory $errorCategory,
		?int $httpStatus = null,
		array $transportErrorCodes = [],
	): self
	{
		$result = new self(errorCategory: $errorCategory, httpStatus: $httpStatus);
		$result->addError(new Error(
			'Board signing failed.',
			$errorCategory->value,
			$transportErrorCodes ? ['transportErrorCodes' => $transportErrorCodes] : null,
		));

		return $result;
	}
}
