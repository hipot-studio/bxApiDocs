<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Landing;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;

class SetCheckUniqueAddress extends Blank
{
	public function action(): void
	{
		if ((int)$this->context->getAdditionalOptions()->get(AdditionalOptionPart::SiteId) <= 0)
		{
			return;
		}

		Landing::enableCheckUniqueAddress();
	}
}
