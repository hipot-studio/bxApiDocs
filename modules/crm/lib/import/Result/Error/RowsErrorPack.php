<?php

namespace Bitrix\Crm\Import\Result\Error;

use Bitrix\Main\Error;

final class RowsErrorPack
{
	public function __construct(
		public readonly array $rowIndexes,
		/** @var Error[] $errors */
		public readonly array $errors,
	)
	{
	}

	public function getErrorMessages(): array
	{
		return array_map(static fn (Error $error) => $error->getMessage(), $this->errors);
	}
}
