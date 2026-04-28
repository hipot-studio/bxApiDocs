<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Landing;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\TransferException;

class CheckReplacedSite extends Blank
{
	public function action(): void
	{
		$siteId = $this->context->getAdditionalOptions()->get(AdditionalOptionPart::ReplaceSiteId);
		if (!isset($siteId))
		{
			throw new TransferException('Replaced site ID is required');
		}
	}
}
