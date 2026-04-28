<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class SaveTemplates extends Blank
{
	public function action(): void
	{
		$ratio = $this->context->getRatio();
		$saved = $ratio->get(RatioPart::Templates);
		$templatesData = $this->context->getTemplates();
		if (
			!$saved
			&& is_array($templatesData)
		)
		{
			$ratio->set(RatioPart::Templates, $templatesData);
		}
	}
}
