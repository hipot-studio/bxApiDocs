<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Site\Type;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class SetScope extends Blank
{
	public function action(): void
	{
		$ratio = $this->context->getRatio();
		$siteType = $ratio->get(RatioPart::SiteType);
		if (is_string($siteType))
		{
			Type::setScope($siteType);
		}
		else
		{
			$data = $this->context->getData();
			$siteType = $data['TYPE'] ?? Type::SCOPE_CODE_DEFAULT;

			Type::setScope($siteType);

			$siteTypePrepared = Type::getCurrentScopeId() ?? Type::SCOPE_CODE_DEFAULT;
			$ratio->set(RatioPart::SiteType, $siteTypePrepared);
		}
	}
}
