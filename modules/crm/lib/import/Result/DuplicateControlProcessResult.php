<?php

namespace Bitrix\Crm\Import\Result;

use Bitrix\Crm\Result;

final class DuplicateControlProcessResult extends Result
{
	public function __construct(
		public readonly bool $isDuplicate,
		public readonly int $entityTypeId,
		public readonly array $duplicateIds,
	)
	{
		parent::__construct();
	}
}
