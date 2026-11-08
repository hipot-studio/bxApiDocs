<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Embed;

enum EmbedView: string
{
	case CreateHub = 'create';
	case ApplicationCreate = 'applications-create';
	case AgentCreate = 'agents-create';
	case CoworkAdmin = 'cowork-admin';
}
