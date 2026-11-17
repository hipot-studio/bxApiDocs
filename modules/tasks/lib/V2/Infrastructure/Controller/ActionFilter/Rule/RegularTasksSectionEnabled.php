<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter\Rule;

use Attribute;
use Bitrix\Main\Engine\ActionFilter\Attribute\FilterAttributeInterface;
use Bitrix\Main\Engine\ActionFilter\FilterType;

/**
 * Атрибут-гейт раздела «Регулярные задачи» по флагу раздела. Навешивается на action'ы
 * контроллеров раздела; off → действие недоступно.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RegularTasksSectionEnabled implements FilterAttributeInterface
{
	public function getFilters(): array
	{
		return [new \Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter\RegularTasksSectionEnabled()];
	}

	public function getType(): FilterType
	{
		return FilterType::EnablePrefilter;
	}
}
