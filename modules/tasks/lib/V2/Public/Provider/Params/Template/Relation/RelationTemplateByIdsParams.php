<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template\Relation;

class RelationTemplateByIdsParams
{
	public function __construct(
		public array $templateIds,
		public int $userId,
		public bool $withSubTemplates = true,
	)
	{

	}
}
