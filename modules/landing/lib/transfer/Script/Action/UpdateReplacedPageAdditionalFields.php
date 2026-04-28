<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Landing;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Script\Action\Closet\ContexterTrait;

class UpdateReplacedPageAdditionalFields extends Blank
{
	use ContexterTrait;
	public function action(): void
	{
		$data = $this->context->getData();
		if (empty($data))
		{
			return;
		}

		$replaceLid = $this->context->getAdditionalOptions()->get(AdditionalOptionPart::ReplacePageId);
		if (!isset($replaceLid))
		{
			return;
		}

		if (!$this->isIndexPage())
		{
			return;
		}

		if (is_array($data['ADDITIONAL_FIELDS']) && !empty($data['ADDITIONAL_FIELDS']))
		{
			Landing::saveAdditionalFields($replaceLid, $data['ADDITIONAL_FIELDS']);
		}
	}
}
