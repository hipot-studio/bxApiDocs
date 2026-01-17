<?php

namespace Bitrix\Intranet\Internal\Integration\Main\EventHandler;

use Bitrix\Im\V2\Entity\Url\RichData;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class AnnualSummaryUrlPreview
{
	public static function buildPreview(array $params): string
	{
		return '<div style="color: #1F86FF; font-weight: 500; line-height: 17px; margin-bottom: 8px; font-size: 13px;">' . Loc::getMessage("INTRANET_ANNUAL_SUMMARY_URL_PREVIEW_TITLE") . '</div>
<div><img style="width: 305px; height: 140px" src="/bitrix/images/intranet/annual-summary/rich.png" alt="' . Loc::getMessage("INTRANET_ANNUAL_SUMMARY_URL_PREVIEW_TITLE") . '"></div>';
	}

	public static function checkUserReadAccess(array $params): bool
	{
		return true;
	}

	public static function getImRich($params)
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		if (!class_exists('\Bitrix\Im\V2\Entity\Url\RichData'))
		{
			return false;
		}

		return (new RichData())
			->setName(Loc::getMessage("INTRANET_ANNUAL_SUMMARY_URL_PREVIEW_TITLE"))
			->setLink($params['URL'])
			->setPreviewUrl('/bitrix/images/intranet/annual-summary/rich.png')
			->setType(RichData::DYNAMIC_TYPE)
		;
	}

	public static function getImAttach(array $params)
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		$attach = new \CIMMessageParamAttach(1, '#E30000');
		$attach->addLink([
			'NAME' => Loc::getMessage("INTRANET_ANNUAL_SUMMARY_URL_PREVIEW_TITLE"),
			'LINK' => $params['URL'],
			'PREVIEW' => '/bitrix/images/intranet/annual-summary/rich.png',
			'WIDTH' => 305,
			'HEIGHT' => 140,
		]);

		return $attach;
	}
}
