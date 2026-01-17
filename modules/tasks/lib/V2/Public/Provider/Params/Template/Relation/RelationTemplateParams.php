<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template\Relation;

use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Provider\Params\SelectInterface;

class RelationTemplateParams extends GridParams
{
	public function __construct(
		public int $userId,
		public int $templateId,
		public PagerInterface $pager,
		public bool $checkRootAccess = true,
		public ?SelectInterface $select = null,
	)
	{
		parent::__construct(
			pager: $pager,
			select: $select,
		);
	}
}
