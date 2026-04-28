<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script;

use Bitrix\Landing\Transfer\Requisite;
use Bitrix\Landing\Transfer\Requisite\Dictionary;

class Factory
{
	public function writeScript(Requisite\Context $context): IScript
	{
		$additional = $context->getAdditionalOptions();

		$scriptClass = ImportSite::class;
		if ($additional->get(Dictionary\AdditionalOptionPart::ReplacePageId))
		{
			$scriptClass = ReplacePage::class;
		}
		elseif ($additional->get(Dictionary\AdditionalOptionPart::SiteId))
		{
			$scriptClass = ImportPage::class;
		}
		elseif ($additional->get(Dictionary\AdditionalOptionPart::ReplaceSiteId))
		{
			$scriptClass = ReplaceSite::class;
		}

		// todo: do

		return new $scriptClass($context);
	}
}
