<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\History;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class SetHistoryReplaceSite extends Blank
{
	public function action(): void
	{
		// todo: for future, not using yet
		$useHistory = false;
		if ($useHistory)
		{
			$siteId = $this->context->getSiteId();
			if (!$siteId)
			{
				return;
			}

			$ratio = $this->context->getRatio();

			$landings = $ratio->get(RatioPart::Landings) ?? [];
			$landingsBefore = $ratio->get(RatioPart::LandingsBefore) ?? [];
			$additionalFieldsSite = $ratio->get(RatioPart::AdditionalFieldsSite) ?? [];
			$additionalFieldsSiteBefore = $ratio->get(RatioPart::AdditionalFieldsSiteBefore) ?? [];
			$templateLinkingBefore = $ratio->get(RatioPart::TemplateLinkingBefore) ?? [];
			$templateLinking = $ratio->get(RatioPart::TemplateLinking) ?? [];

			History::activate();
			$history = new History($siteId, History::ENTITY_TYPE_LANDING);
			$history->push('REPLACE_SITE_LANDINGS', [
				'siteId' => $siteId,

				'landingsBefore' => $landingsBefore,
				'landings' => $landings,

				'templateLinkingBefore' => $templateLinkingBefore,
				'templateLinking' => $templateLinking,

				'additionalFieldsSiteBefore' => $additionalFieldsSiteBefore,
				'additionalFieldsSite' => $additionalFieldsSite,
			]);
		}
	}
}
