<?php

namespace Bitrix\Crm\Service\Router\Page\RepeatSale\Sandbox;

use Bitrix\Crm\Service\Router\AbstractPage;
use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\Router\Enum\Scope;
use Bitrix\Crm\Service\Router\Route;

final class SandboxPage extends AbstractPage
{
	private const COMPONENT_NAME = 'bitrix:crm.repeat_sale.sandbox';

	public function component(): Contract\Component
	{
		return new Component(self::COMPONENT_NAME);
	}

	public static function routes(): array
	{
		return [
			(new Route('repeat-sale-sandbox/'))
				->setRelatedComponent(self::COMPONENT_NAME)
			,
		];
	}

	public static function scopes(): array
	{
		return [
			Scope::Crm,
		];
	}
}
