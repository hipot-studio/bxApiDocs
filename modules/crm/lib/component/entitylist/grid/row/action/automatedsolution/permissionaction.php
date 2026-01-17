<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Row\Action\AutomatedSolution;

use Bitrix\Crm\Integration\Analytics\Builder\Security\ViewEvent;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Router;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class PermissionAction extends BaseAction
{
	public function __construct(
		private readonly Router $router,
	)
	{
	}

	public static function getId(): ?string
	{
		return 'permission';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		Container::getInstance()->getLocalization()->loadMessages();

		return Loc::getMessage('CRM_COMMON_PERMISSIONS_SETTINGS_ITEM');
	}

	public function getControl(array $rawFields): ?array
	{
		$id = $rawFields['ID'] ?? null;
		if ($id <= 0)
		{
			return null;
		}

		$crmPermsViewEventBuilder = new ViewEvent(); // @todo *automaticsolution

		$permissions = $rawFields['PERMISSIONS'] ?? null;
		if ($permissions === null)
		{
			return null;
		}

		$baseUri = $this->router->getCustomSectionPermissionsUrl($permissions);
		if ($baseUri === null)
		{
			return null;
		}

		$url = (string)$crmPermsViewEventBuilder->buildUri($baseUri);
		$this->onclick = 'BX.Crm.Page.openSlider("' . $url . '", {cacheable: false})';

		return parent::getControl($rawFields);
	}
}