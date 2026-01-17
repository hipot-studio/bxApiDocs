<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Rag\Result;

use Bitrix\Bizproc\Result;

class KnowledgeBaseInfoResult extends Result
{
	public function __construct(
		public readonly string $uid,
		public readonly array $fileIds,
		public readonly array $fileIdsReplaces,
	)
	{
		parent::__construct();
	}
}