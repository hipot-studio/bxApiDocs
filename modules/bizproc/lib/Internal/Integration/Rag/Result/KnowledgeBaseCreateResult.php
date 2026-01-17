<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Rag\Result;

use Bitrix\Bizproc\Result;

class KnowledgeBaseCreateResult extends Result
{
	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
	)
	{
		parent::__construct();
	}
}