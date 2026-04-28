<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Hook\Page\B24button;
use Bitrix\Landing\Hook\Page\Copyright;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;

class PrepareSiteAdditionalFields extends Blank
{
	public function action(): void
	{
		$data = $this->context->getData();
		if (!is_array($data))
		{
			return;
		}
		$additionalFields = $data['ADDITIONAL_FIELDS'] ?? [];
		$additional = $this->context->getAdditionalOptions();

		if (
			!$this->isImportPageScript()
			&& !$this->isReplaceScript()
		)
		{
			$title = $additional->get(AdditionalOptionPart::Title);
			if ($title)
			{
				$data['TITLE'] = $title;
			}
		}

		//default widget value
		$buttons = B24button::getButtons();
		$buttonKeys = array_keys($buttons);
		if (!empty($buttonKeys))
		{
			$additionalFields['B24BUTTON_CODE'] = $buttonKeys[0];
			$additionalFields['B24BUTTON_COLOR'] = B24button::COLOR_TYPE_SITE;
		}
		else
		{
			$additionalFields['B24BUTTON_CODE'] = 'N';
		}

		//default site boost
		$additionalFields['SPEED_USE_WEBPACK'] = 'Y';
		$additionalFields['SPEED_USE_LAZY'] = 'Y';

		//default powered by b24
		$additionalFields['COPYRIGHT_SHOW'] = 'Y';
		$additionalFields['COPYRIGHT_CODE'] = Copyright::getRandomPhraseId();

		//default cookie
		if (in_array(Manager::getZone(), ['es', 'de', 'fr', 'it', 'pl', 'uk']))
		{
			$additionalFields['COOKIES_USE'] = 'Y';
		}

		$data['ADDITIONAL_FIELDS'] = $additionalFields;
		$this->context->setData($data);
	}
}
