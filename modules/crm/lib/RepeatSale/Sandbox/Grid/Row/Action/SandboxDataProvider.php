<?php

namespace Bitrix\Crm\RepeatSale\Sandbox\Grid\Row\Action;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Grid\Row\Action\DataProvider;
use Bitrix\Main\Grid\Settings;

final class SandboxDataProvider extends DataProvider
{
	public function __construct(
		Settings $settings,
		private readonly UserPermissions $userPermissions,
	)
	{
		parent::__construct($settings);
	}

	public function prepareActions(): array
	{
		$actions = [];
		$actions[] = new DeleteAction($this->getSettings(), $this->userPermissions);

		return $actions;
	}
}
