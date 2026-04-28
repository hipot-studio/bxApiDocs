<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Hook;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RunDataPart;
use Bitrix\Landing\Transfer\Script\Action\Closet\ContexterTrait;

class SaveReplacedPageAdditionalFields extends Blank
{
	use ContexterTrait;

	public function action(): void
	{
		$replaceLid = $this->context->getAdditionalOptions()->get(AdditionalOptionPart::ReplacePageId);
		if (!$replaceLid)
		{
			return;
		}

		$additionalFields = [];
		$hooks = Hook::getData($replaceLid, Hook::ENTITY_TYPE_LANDING);
		foreach ($hooks as $hook => $fields)
		{
			foreach ($fields as $code => $field)
			{
				$additionalFields[$hook . '_' . $code] = $field;
			}
		}
		$additionalFields = $this->filterAdditionalFields($additionalFields);

		$this->context->getRunData()->set(RunDataPart::AdditionalFieldsBefore, $additionalFields);
	}
}
