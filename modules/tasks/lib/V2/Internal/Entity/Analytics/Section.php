<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Analytics;

enum Section: string
{
	case Tasks = 'tasks';
	case Lead = 'lead';
	case Project = 'project';
	case Scrum = 'scrum';
	case Crm = 'crm';
	case Feed = 'feed';
	case Chat = 'chat';
	case Mail = 'mail';
	case Calendar = 'calendar';
	case User = 'user';
	case Comment = 'comment';
	case Flows = 'flows';
	case Collab = 'collab';
	case OnboardingNotification = 'onboarding_notification';
	case Templates = 'templates';
	case BizProc = 'bizproc';
}
