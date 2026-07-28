<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Main\Localization\Loc;

final class SuggestProvider
{
	/**
	 * Returns localized suggest phrases for the default Copilot role.
	 *
	 * @return list<?string>
	 */
	public static function getList(): array
	{
		if (!Features::get()->isBitrixGptV2Available)
		{
			return [];
		}

		return [
			Loc::getMessage('IM_COPILOT_SUGGEST_PHRASE_1'),
			Loc::getMessage('IM_COPILOT_SUGGEST_PHRASE_2'),
			Loc::getMessage('IM_COPILOT_SUGGEST_PHRASE_3'),
		];
	}
}
