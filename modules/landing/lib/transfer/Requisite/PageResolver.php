<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite;

use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

final class PageResolver
{
	public static function getIndexPageId(Context $context): int
	{
		$savedIndex = (int)$context->getRatio()->get(RatioPart::IndexPageId);
		if ($savedIndex > 0)
		{
			return $savedIndex;
		}

		$replacedPageId = (int)$context->getAdditionalOptions()->get(AdditionalOptionPart::ReplacePageId);
		if ($replacedPageId > 0)
		{
			return $replacedPageId;
		}

		$ratio = $context->getRatio();

		$landings = $ratio->get(RatioPart::Landings) ?? [];
		$indexPageId = (int)reset($landings);

		$specialPages = $ratio->get(RatioPart::SpecialPages) ?? [];
		$specialIndex = (int)($specialPages['LANDING_ID_INDEX'] ?? 0);
		if (isset($landings[$specialIndex]))
		{
			$indexPageId = (int)$landings[$specialIndex];
		}

		return $indexPageId;
	}
}

