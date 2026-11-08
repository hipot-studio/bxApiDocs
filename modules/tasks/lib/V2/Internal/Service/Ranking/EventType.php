<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Ranking;

enum EventType: string
{
	case Create = 'create';
	case Update = 'update';
	case Comment = 'comment';
	case Open = 'open';
	case Relation = 'relation';
}
