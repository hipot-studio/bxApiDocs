<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Landing;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\TransferException;

class CheckReplacedPage extends Blank
{
	public function action(): void
	{
		$pageId = $this->context->getAdditionalOptions()->get(AdditionalOptionPart::ReplacePageId);
		if (!isset($pageId))
		{
			throw new TransferException('Replaced page ID is required');
		}

		Landing::setEditMode();
		$landing = Landing::createInstance($pageId);
		if (!$landing->exist())
		{
			throw new TransferException('Replaced landing not found');
		}
	}

}
