<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

enum GroupTypes: string
{
	case Group = 'group';
	case Project = 'project';
	case Collab = 'collab';
	case Scrum = 'scrum';
}
