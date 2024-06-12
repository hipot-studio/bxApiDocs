<?php

namespace Bitrix\Intranet\User\Grid\Row\Action;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\Localization\Loc;

class DeleteAction extends JsGridAction
{
	public static function getId(): ?string
	{
		return 'delete';
	}

	public function processRequest(\Bitrix\Main\HttpRequest $request): ?\Bitrix\Main\Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('INTRANET_USER_GRID_ROW_ACTIONS_DELETE') ?? '';
	}

	public function isAvailable(array $rawFields): bool
	{
		return !empty($rawFields['CONFIRM_CODE'])
			&& $rawFields['ID'] !== CurrentUser::get()->getId()
			&& $rawFields['ACTIVE'] === 'Y';
	}

	public function getExtensionMethod(): string
	{
		return 'activityAction';
	}

	protected function getActionParams(array $rawFields): array
	{
		return [
			'action' => 'delete',
			'userId' => $rawFields['ID'],
		];
	}
}