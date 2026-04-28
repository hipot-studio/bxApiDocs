<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Landing;
use Bitrix\Landing\Site;
use Bitrix\Landing\TemplateRef;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class UpdateReplacedSitePages extends Blank
{
	public function action(): void
	{
		$siteId = $this->context->getAdditionalOptions()->get(AdditionalOptionPart::ReplaceSiteId);
		$siteId = (int)$siteId;
		if ($siteId > 0)
		{
			return;
		}

		$ratio = $this->context->getRatio();
		$landingsBefore = $ratio->get(RatioPart::LandingsBefore) ?? [];
		$landings = $ratio->get(RatioPart::Landings) ?? [];
		foreach ($landingsBefore as $lidToDelete)
		{
			TemplateRef::deleteArea($lidToDelete);
			Landing::markDelete($lidToDelete);
		}

		$specialPages = $ratio->get(RatioPart::SpecialPages);
		if (
			$specialPages
			&& $specialPages['LANDING_ID_INDEX']
			&& $landings[$specialPages['LANDING_ID_INDEX']]
		)
		{
			$index = (int)$landings[$specialPages['LANDING_ID_INDEX']];
			Site::update($siteId, [
				'LANDING_ID_INDEX' => $index,
			]);
		}

	}
}
