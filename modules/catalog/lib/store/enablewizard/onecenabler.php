<?php

namespace Bitrix\Catalog\Store\EnableWizard;

use Bitrix\Catalog\Access\AccessController;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\Config\State;
use Bitrix\Catalog\v2\Integration\Landing\ShopManager;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class OnecEnabler extends Enabler
{
	public static function enable(array $options = []): Result
	{
		$result = new Result();

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_PRODUCT_EDIT))
		{
			$result->addError(
				new Error(
					Loc::getMessage('ONEC_ENABLER_PRODUCT_DEACTIVATION_INSUFFICIENT_RIGHTS'),
					0,
					[
						'analyticsCode' => '1c_no_access_product_edit',
					],
				)
			);

			return $result;
		}

		$result = parent::enable($options);
		if (!$result->isSuccess())
		{
			return $result;
		}

		ProductDisabler::disable();
		(new ShopManager())->unpublishShops();
		State::setIsExternalCatalog(true);

		return $result;
	}

	public static function disable(): Result
	{
		$r = parent::disable();
		if (!$r->isSuccess())
		{
			return $r;
		}

		State::setIsExternalCatalog(false);

		return $r;
	}
}
