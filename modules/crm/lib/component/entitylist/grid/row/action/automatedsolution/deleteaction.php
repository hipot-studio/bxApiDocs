<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Row\Action\AutomatedSolution;

use Bitrix\Crm\AutomatedSolution\AutomatedSolutionManager;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class DeleteAction extends BaseAction
{
	public function __construct(
		private readonly Settings $settings,
		private readonly AutomatedSolutionManager $automatedSolutionManager,
		private readonly UserPermissions $userPermissions,
	)
	{
	}

	public static function getId(): ?string
	{
		return 'delete';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		$id = (int)$request->getPost('id');
		if ($id <= 0)
		{
			return null;
		}

		$result = new Result();
		if (!$this->userPermissions->canWriteConfig())
		{
			return $result->addError(ErrorCode::getAccessDeniedError());
		}

		return $this->automatedSolutionManager->deleteAutomatedSolution($id);
	}

	protected function getText(): string
	{
		Container::getInstance()->getLocalization()->loadMessages();

		return Loc::getMessage('CRM_COMMON_ACTION_DELETE');
	}

	public function getControl(array $rawFields): ?array
	{
		$id = (int)($rawFields['ID'] ?? null);
		if ($id <= 0)
		{
			return null;
		}

		$safeGridId = \CUtil::JSEscape($this->settings->getID());
		$this->onclick = "BX.Main.gridManager.getInstanceById('{$safeGridId}').sendRowAction('delete', { id: {$id} });";

		return parent::getControl($rawFields);
	}
}
