<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity\Enum;

/**
 * Rule that lets automation complete the activity on its own.
 *
 * {@see self::OnStageChanged} is the only rule that has ever been applied: automation
 * completes the activity when the stage of the owner item changes.
 *
 * The correspondence with the stored column is owned by the field map, not by this enum.
 */
enum AutocompleteRule: string
{
	case None = 'none';
	case OnStageChanged = 'onStageChanged';
}
