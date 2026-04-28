<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Script\Action\Closet\ContexterTrait;

class UpdateReplacedPageAdditionalFiles extends Blank
{
	use ContexterTrait;

	public function action(): void
	{
		$replacedLid = $this->context->getAdditionalOptions()->get(AdditionalOptionPart::ReplacePageId);
		if (!isset($replacedLid))
		{
			return;
		}
		$this->saveAdditionalFilesToLanding((int)$replacedLid);
	}
}
