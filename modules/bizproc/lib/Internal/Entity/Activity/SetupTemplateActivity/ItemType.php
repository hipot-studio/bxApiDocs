<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;

enum ItemType: string
{
	case Title = 'title';
	case Description = 'description';
	case Delimiter = 'delimiter';
	case Constant = 'constant';
	case TitleWithIcon = 'titleWithIcon';
}
