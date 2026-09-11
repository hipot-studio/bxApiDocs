<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Rights;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\TransferException;

class CheckReplacedSite extends Blank
{
	public function action(): void
	{
		$siteId = (int)$this->context->getAdditionalOptions()->get(AdditionalOptionPart::ReplaceSiteId);
		if ($siteId <= 0 || !Rights::hasAccessForSite($siteId, Rights::ACCESS_TYPES['edit']))
		{
			throw new TransferException('Replaced site ID is required');
		}
	}
}
