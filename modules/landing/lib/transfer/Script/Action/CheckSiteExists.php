<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Site;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\TransferException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;

class CheckSiteExists extends Blank
{
	public function action(): void
	{
		$siteId = $this->context->getSiteId();
		if (!isset($siteId))
		{
			throw new TransferException('SIte is not configured');
		}

		$checkByType = Site::getList([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $siteId,
			],
		]);
		if (!$checkByType->fetch())
		{
			throw new TransferException('Site is not exists or has incorrect type.');
		}
	}
}