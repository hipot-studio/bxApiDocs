<?php

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

enum Status: string
{
	case Search = 'search';
	case TaskCreationStarted = 'taskCreationStarted';
	case TaskCreationCompleted = 'taskCreationCompleted';
	case ResultCreationStarted = 'resultCreationStarted';
	case ResultCreationCompleted = 'resultCreationCompleted';
	case NotFound = 'notFound';
}
