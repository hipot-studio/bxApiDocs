<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\Assembler\Field\ListFieldAssembler;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Internal\Entity\PrivacyType;

class PrivacyTypeFieldAssembler extends ListFieldAssembler
{
	protected function getNames(): array
	{
		return [
			'open' => Loc::getMessage('SONET_V2_GRID_PRIVACY_TYPE_OPEN') ?? '',
			'closed' => Loc::getMessage('SONET_V2_GRID_PRIVACY_TYPE_CLOSED') ?? '',
			PrivacyType::LEGACY_SCRUM_SECRET => Loc::getMessage('SONET_V2_GRID_PRIVACY_TYPE_SECRET') ?? '',
		];
	}
}
