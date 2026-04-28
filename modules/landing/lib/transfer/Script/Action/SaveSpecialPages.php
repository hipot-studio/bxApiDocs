<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Hook\Page\B24button;
use Bitrix\Landing\Hook\Page\Copyright;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Site\Type;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class SaveSpecialPages extends Blank
{
	public function action(): void
	{
		$data = $this->context->getData();
		$ratio = $this->context->getRatio();

		if ($ratio->get(RatioPart::SpecialPages))
		{
			return;
		}

		$ratio->set(RatioPart::SpecialPages, [
			'LANDING_ID_INDEX' => (int)($data['LANDING_ID_INDEX'] ?? 0),
			'LANDING_ID_404' => (int)($data['LANDING_ID_404'] ?? 0),
			'LANDING_ID_503' => (int)($data['LANDING_ID_503'] ?? 0),
		]);
	}
}
