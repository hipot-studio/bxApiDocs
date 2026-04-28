<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Site;
use Bitrix\Landing\Syspage;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class UpdateSpecialPages extends Blank
{
	public function action(): void
	{
		$ratio = $this->context->getRatio();
		$landings = $ratio->get(RatioPart::Landings) ?? [];
		$specialPages = $ratio->get(RatioPart::SpecialPages) ?? [];
		$sysPages = $ratio->get(RatioPart::SysPages) ?? [];
		$siteId = (int)$this->context->getSiteId();
		if ($siteId <= 0)
		{
			return;
		}

		// replace special pages in site (503, 404)
		if (!empty($specialPages))
		{
			foreach ($specialPages as $code => $id)
			{
				$specialPages[$code] = $landings[$id] ?? 0;
			}
			Site::update($siteId, $specialPages);
		}

		// system pages
		if (!empty($sysPages))
		{
			foreach ($sysPages as $sysPage)
			{
				if (isset($landings[$sysPage['LANDING_ID']]))
				{
					Syspage::set($siteId, $sysPage['TYPE'], $landings[$sysPage['LANDING_ID']]);
				}
			}
		}
	}
}
