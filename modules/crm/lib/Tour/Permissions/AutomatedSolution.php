<?php

namespace Bitrix\Crm\Tour\Permissions;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Tour\AbstractPermissions;
use Bitrix\Crm\Tour\Mixin\HasEntitySupport;
use Bitrix\Main\Localization\Loc;

final class AutomatedSolution extends AbstractPermissions
{
	use HasEntitySupport;

	protected const OPTION_NAME = 'automated-solution-permissions-tour';

	protected function canShow(): bool
	{
		$router = Container::getInstance()->getRouter();

		return
			$router->getRoot() !== $router->getDefaultRoot()
			&& parent::canShow()
		;
	}

	protected function hasPermissions(): bool
	{
		return
			$this->entityTypeId !== null
			&& $this->userPermissions->isAdminForEntity($this->entityTypeId)
		;
	}

	protected function target(): string
	{
		return '.main-buttons-item[data-id="perms"][data-disabled="false"]';
	}

	protected function reserveTargets(): array
	{
		return [
			'.main-buttons-item.main-buttons-item-more-default.main-buttons-item-more.--has-menu',
		];
	}

	protected function title(): string
	{
		return Loc::getMessage('CRM_TOUR_PERMISSIONS_AUTOMATED_SOLUTION_TITLE');
	}

	protected function text(): string
	{
		return Loc::getMessage('CRM_TOUR_PERMISSIONS_AUTOMATED_SOLUTION_TEXT');
	}
}
