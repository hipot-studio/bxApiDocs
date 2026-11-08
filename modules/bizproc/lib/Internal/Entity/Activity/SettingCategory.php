<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity;

/**
 * Lifecycle category of an activity setting: who owns its value and what an external AI agent may do with it.
 * Exactly one category belongs to a setting; the contour reads it to decide what to accept, what to carry over
 * from the published template and what to compute on its own.
 */
enum SettingCategory: string
{
	case EditableTyped = 'editableTyped';
	case EditableFreeMap = 'editableFreeMap';
	case Carried = 'carried';
	case Computed = 'computed';
	case ServerManaged = 'serverManaged';
	case Enforced = 'enforced';

	/**
	 * Whether the agent may author the value. Only editable settings are published as the block schema:
	 * the rest are either carried over verbatim or written by the server.
	 */
	public function isEditable(): bool
	{
		return $this === self::EditableTyped || $this === self::EditableFreeMap;
	}
}
