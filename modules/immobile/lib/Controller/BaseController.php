<?php

namespace Bitrix\ImMobile\Controller;

use Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter as ImAuthorizationPrefilter;
use Bitrix\ImMobile\Controller\Filter\AuthorizationPrefilter as ImMobileAuthorizationPrefilter;
use Bitrix\Main\Loader;

Loader::requireModule('im');

abstract class BaseController extends \Bitrix\Im\V2\Controller\BaseController
{
	protected function getDefaultPreFilters()
	{
		return array_map(
			static fn ($filter) => $filter instanceof ImAuthorizationPrefilter
				? new ImMobileAuthorizationPrefilter()
				: $filter,
			parent::getDefaultPreFilters()
		);
	}
}
