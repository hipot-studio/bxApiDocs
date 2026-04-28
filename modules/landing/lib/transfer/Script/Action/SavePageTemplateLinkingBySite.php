<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RunDataPart;
use Bitrix\Landing\Transfer\Script\Action\Closet\ContexterTrait;

class SavePageTemplateLinkingBySite extends Blank
{
	use ContexterTrait;

	public function action(): void
	{
		$isIndexPage = $this->isIndexPage();
		if (!$isIndexPage)
		{
			return;
		}

		$data = $this->context->getData();
		$siteId = $data['SITE_ID'] ?? null;
		$newLid = $this->context->getRunData()->get(RunDataPart::NewId);
		if (!isset($data, $siteId, $newLid))
		{
			return;
		}

		$ratio = $this->context->getRatio();
		$templateLinking = $ratio->get(RatioPart::TemplateLinking) ?? [];
		if (empty($templateLinking))
		{
			return;
		}

		$siteTemplate = $templateLinking[-1 * $siteId] ?? [];
		if (!empty($siteTemplate))
		{
			$templateLinking[$newLid] = $siteTemplate;
			unset($templateLinking[-1 * $siteId]);
		}

		$this->context->getRatio()->set(RatioPart::TemplateLinking, $templateLinking);
	}
}
