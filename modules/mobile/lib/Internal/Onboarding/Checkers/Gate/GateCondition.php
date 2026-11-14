<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Gate;

/**
 * Gate conditions the campaign document may address through `steps[].gate`. A token
 * outside this enum is dropped by the configuration layer and the step keeps the
 * default condition of StepGateFactory - the document cannot leave a step ungated by
 * a typo.
 */
enum GateCondition: string
{
	case NONE = 'none';
	case COWORKER_JOINED = 'coworker_joined';
	case COWORKER_JOINED_BY_INVITE_OR_AFTER_PUSH = 'coworker_joined_by_invite_or_after_push';
}
