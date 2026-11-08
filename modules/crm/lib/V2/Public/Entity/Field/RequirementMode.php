<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Field;

enum RequirementMode: string
{
	case None = 'none';
	case All = 'all';
	case Stages = 'stages';
}
