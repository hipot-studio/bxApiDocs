<?php

namespace Bitrix\ImMobile\Controller\Filter;

use Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter as ImAuthorizationPrefilter;
use Bitrix\ImMobile\Controller as ImMobileController;
use Bitrix\Main\Loader;

Loader::requireModule('im');

class AuthorizationPrefilter extends ImAuthorizationPrefilter
{
	private const ADDITIONAL_GUEST_CONTROLLERS = [
		ImMobileController\Messenger::class => true,
		ImMobileController\Settings::class => true,
		ImMobileController\Tab\Chat::class => true,
	];

	protected function isGuestControllerAllowed(): bool
	{
		if (parent::isGuestControllerAllowed())
		{
			return true;
		}

		$controllerClass = $this->getAction()->getController()::class;

		return isset(self::ADDITIONAL_GUEST_CONTROLLERS[$controllerClass]);
	}
}
