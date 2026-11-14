<?php

namespace Bitrix\Mobile\Internal\Onboarding\Config;

/**
 * Send conditions of a final push the code implements. The campaign document names one
 * by its wire value; a name outside this enum makes the final unusable, so a gate the
 * document asked for can never be silently dropped.
 */
enum FinalCondition: string
{
	case APP_NOT_OPENED_AFTER_MEETUP = 'app_not_opened_after_meetup';
}
