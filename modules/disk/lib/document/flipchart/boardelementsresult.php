<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Disk\Document\Flipchart\Enum\BoardIndexErrorCategory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class BoardElementsResult extends Result
{
	/**
	 * @param array<mixed> $items JSON objects, including nested ones, remain stdClass instances.
	 */
	private function __construct(
		public readonly array $items = [],
		public readonly ?BoardIndexErrorCategory $errorCategory = null,
		public readonly ?int $httpStatus = null,
	)
	{
		parent::__construct();
	}

	public static function createSuccess(array $items): self
	{
		return new self(items: $items);
	}

	public static function createError(BoardIndexErrorCategory $errorCategory, ?int $httpStatus = null): self
	{
		$result = new self(errorCategory: $errorCategory, httpStatus: $httpStatus);
		$result->addError(new Error('Board elements request failed.', $errorCategory->value));

		return $result;
	}
}
