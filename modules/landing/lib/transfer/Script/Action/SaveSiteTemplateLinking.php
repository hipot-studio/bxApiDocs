<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\TransferException;

class SaveSiteTemplateLinking extends Blank
{
	public function action(): void
	{
		$siteId = $this->context->getSiteId();
		if (!$siteId)
		{
			// no need exception, checking on CheckSiteExists action
			return;
		}
		$data = $this->context->getData();

		$tplId = (int)($data['TPL_ID'] ?? 0);
		$tplRef = (array)($data['TEMPLATE_REF'] ?? []);
		if ($tplId <= 0 || empty($tplRef))
		{
			return;
		}

		$templateLinking = [];
		$templateLinking[-1 * $siteId] = [
			'TPL_ID' => (int)$data['TPL_ID'],
			'TEMPLATE_REF' => (array)($data['TEMPLATE_REF'] ?? []),
		];
		$this->context->getRatio()->set(RatioPart::TemplateLinking, $templateLinking);
	}
}
