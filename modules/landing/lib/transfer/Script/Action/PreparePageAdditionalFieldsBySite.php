<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class PreparePageAdditionalFieldsBySite extends Blank
{
	public function action(): void
	{
		$additionalFieldsSite = $this->context->getRatio()->get(RatioPart::AdditionalFieldsSite);
		if (!isset($additionalFieldsSite))
		{
			return;
		}

		$data = $this->context->getData();
		$additionalFields = $data['ADDITIONAL_FIELDS'] ?? [];
		foreach ($additionalFieldsSite as $code => $field)
		{
			if (!isset($additionalFields[$code]))
			{
				$additionalFields[$code] = $field;
			}
		}

		$data['ADDITIONAL_FIELDS'] = $additionalFields;
		$this->context->setData($data);
	}
}
