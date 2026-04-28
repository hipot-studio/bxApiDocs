<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Metrika;
use Bitrix\Landing\Site\Type;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class SendAnalytic extends Blank
{
	public function action(): void
	{
		$additional = $this->context->getAdditionalOptions();
		$metrikaEvent = Metrika\Events::tryFrom(
			$additional->get(AdditionalOptionPart::MetrikaEvent) ?? ''
		);

		if (isset($metrikaEvent))
		{
			$ratio = $this->context->getRatio();
			$siteType = $ratio->get(RatioPart::SiteType) ?? '';

			$metrikaTool = Metrika\Tools::getBySiteType($siteType);
			$metrikaCategory = Metrika\Categories::getBySiteType($siteType);

			$metrika = new Metrika\Metrika(
				$metrikaCategory,
				$metrikaEvent,
				$metrikaTool,
			);
			$metrika->setStatus(Metrika\Statuses::Success);
			$metrika->setType(Metrika\Types::template);

			$metrikaSection = $additional->get(AdditionalOptionPart::MetrikaSection);
			if (isset($metrikaSection))
			{
				$metrika->setSection(Metrika\Sections::tryFrom($metrikaSection));
			}

			$metrika
				->setParam(1, 'appCode', $this->context->getAppCode() ?? '')
				->setParam(3, 'siteId', (string)($this->context->getSiteId() ?? 0))
			;

			Type::onTransferFinishAnalyticSend(($this->context->getSiteId() ?? 0), $metrika);

			$metrika->send();
		}
	}
}
