<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Script\Action\Closet\ContexterTrait;

class PreparePageAdditionalFields extends Blank
{
	use ContexterTrait;

	public function action(): void
	{
		$data = $this->context->getData();
		if (!is_array($data))
		{
			return;
		}
		$additionalFields = $data['ADDITIONAL_FIELDS'] ?? [];
		$additional = $this->context->getAdditionalOptions();

		// inherit from site
		unset(
			$additionalFields['B24BUTTON_CODE'],
			$additionalFields['B24BUTTON_COLOR'],
			$additionalFields['B24BUTTON_COLOR_VALUE'],
		);

		$isIndexPage = $this->isIndexPage();
		if ($isIndexPage)
		{
			$title = $additional->get(AdditionalOptionPart::Title);
			if (isset($title))
			{
				$additionalFields['METAOG_TITLE'] = $title;
				$additionalFields['METAMAIN_TITLE'] = $title;

				// set template name to mainpage title
				if (
					$this->isImportPageScript()
					|| $this->isReplaceSiteScript()
				)
				{
					$data['TITLE'] = $title;
				}
			}

			$description = (string)$additional->get(AdditionalOptionPart::Description);
			if ($description !== '')
			{
				$additionalFields['METAOG_DESCRIPTION'] = $description;
				$additionalFields['METAMAIN_DESCRIPTION'] = $description;
			}
		}

		$data['ADDITIONAL_FIELDS'] = $additionalFields;
		$this->context->setData($data);
	}
}
