<?php

namespace Bitrix\Crm\Badge\Type;

use Bitrix\Crm\Badge\Badge;
use Bitrix\Crm\Badge\ValueItem;
use Bitrix\Crm\Badge\ValueItemOptions;
use Bitrix\Main\Localization\Loc;

final class EntityExclusionStatus extends Badge
{
	protected const TYPE = 'entity_exclusion_status';

	public const REACTION_REQUIRES_VALUE = 'reaction';

	public function getFieldName(): string
	{
		return Loc::getMessage('CRM_BADGE_ENTITY_EXCLUSION_STATUS_FIELD_NAME');
	}

	public function getValuesMap(): array
	{
		return [
			new ValueItem(
				self::REACTION_REQUIRES_VALUE,
				Loc::getMessage('CRM_BADGE_ENTITY_EXCLUSION_STATUS_VALUE'),
				ValueItemOptions::TEXT_COLOR_WARNING,
				ValueItemOptions::BG_COLOR_WARNING
			),
		];
	}

	public function getType(): string
	{
		return self::TYPE;
	}
}
