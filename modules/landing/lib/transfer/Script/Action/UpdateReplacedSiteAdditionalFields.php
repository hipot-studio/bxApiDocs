<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Site;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class UpdateReplacedSiteAdditionalFields extends Blank
{
	public function action(): void
	{
		$data = $this->context->getData();
		if (empty($data))
		{
			return;
		}

		$replaceSite = $this->context->getAdditionalOptions()->get(AdditionalOptionPart::ReplaceSiteId);
		if (!isset($replaceSite))
		{
			return;
		}

		$additionalFieldsSite = $this->context->getRatio()->get(RatioPart::AdditionalFieldsSite);
		if (is_array($additionalFieldsSite) && !empty($additionalFieldsSite))
		{
			Site::saveAdditionalFields($replaceSite, $additionalFieldsSite);
		}
	}
}
