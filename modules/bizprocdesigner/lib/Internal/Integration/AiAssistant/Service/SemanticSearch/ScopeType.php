<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch;

enum ScopeType: string
{
	case Fields = 'fields';
	case Blocks = 'blocks';
}
