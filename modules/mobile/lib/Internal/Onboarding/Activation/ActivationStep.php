<?php

namespace Bitrix\Mobile\Internal\Onboarding\Activation;

enum ActivationStep: string
{
	case CREATE_TASK = 'create_task';
	case CREATE_DEAL = 'create_deal';
	case INVITE = 'invite';
	case MESSAGE = 'message';
	case WEB = 'web';
}
