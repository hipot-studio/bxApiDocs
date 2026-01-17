<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Rag\Result;

use Bitrix\Bizproc\Result;
use Bitrix\Rag\Public\Dto\KnowledgeBaseInfoDto;

class KnowledgeBaseGetInfoResult extends Result
{
	public function __construct(
		public readonly KnowledgeBaseInfoDto $info,
	)
	{
		parent::__construct();
	}
}