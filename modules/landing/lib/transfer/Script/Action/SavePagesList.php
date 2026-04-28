<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\TemplateRef;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class SavePagesList extends Blank
{
	public function action(): void
	{
		$siteId = $this->context->getSiteId();
		if (!is_int($siteId))
		{
			return;
		}

		$landings = $this->getLandingList([
			'select' => ['ID', 'TPL_ID'],
			'filter' => [
				'SITE_ID' => $siteId,
				'=DELETED' => 'N',
			],
		]);
		$landingsBefore = [];
		$landingsLinkingBefore = [];
		while ($landing = $landings->fetch())
		{
			$lid = (int)$landing['ID'];
			$landingsBefore[] = $lid;

			// todo: move to external method
			$tmpLinking = TemplateRef::getForLanding($lid);
			if (!empty($tmpLinking))
			{
				$landingsLinkingBefore[$lid] = [
					'TPL_ID' => (int)$landing['TPL_ID'],
					'TEMPLATE_REF' => $tmpLinking,
				];
			}
		}

		if (!empty($landingsBefore))
		{
			$this->context->getRatio()->set(RatioPart::LandingsBefore, $landingsBefore);
		}
		if (!empty($landingsLinkingBefore))
		{
			$this->context->getRatio()->set(RatioPart::TemplateLinkingBefore, $landingsLinkingBefore);
		}
	}
}
