<?php

namespace Bitrix\Crm\RepeatSale\Sandbox\Grid\Row\Action;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Sandbox\FillRepeatSaleTips;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\Sandbox\Entity\RepeatSaleSandboxTable;
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

		return $this->delete($id);
	}

	private function delete(int $id): Result
	{
		$item = RepeatSaleSandboxTable::getById($id)->fetchObject();
		if (!$item)
		{
			return (new Result())->addError(ErrorCode::getNotFoundError());
		}

		if ($this->userPermissions->item()->canDelete($item->getItemTypeId(), $item->getItemId()))
		{
			return (new Result())->addError(ErrorCode::getAccessDeniedError());
		}

		$itemIdentifier = new ItemIdentifier($item->getItemTypeId(), $item->getItemId());

		$typeId = FillRepeatSaleTips::TYPE_ID;
		QueueTable::deleteByItem($itemIdentifier, $typeId);

		return RepeatSaleSandboxTable::delete($id);
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
