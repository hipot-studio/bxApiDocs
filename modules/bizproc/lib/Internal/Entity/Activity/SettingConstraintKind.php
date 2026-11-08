<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity;

/**
 * Kinds of a cross-setting constraint. The list is closed on purpose: a kind the contour does not know
 * cannot be judged, so an activity declaring one makes its description invalid instead of being trusted.
 */
enum SettingConstraintKind: string
{
	case ExactlyOne = 'exactlyOne';
	case AtLeastOne = 'atLeastOne';
}
