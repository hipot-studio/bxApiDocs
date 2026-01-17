<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

enum FeatureType: string
{
	case Reaction = 'reaction';
	case Deal = 'deal';
	case Message = 'message';
	case Board = 'board';
	case Workflow = 'workflow';
	case Site = 'site';
	case Collab = 'collab';
	case Channel = 'channel';
	case CheckIn = 'checkin';
	case Task = 'task';
	case Copilot = 'copilot';
}
