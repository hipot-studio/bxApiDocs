<?php

namespace Bitrix\Intranet\User\Grid\Row\Action;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\Localization\Loc;

class ConfirmAction extends JsGridAction
{
	public static function getId(): ?string
	{
		return 'confirm';
	}

	public function processRequest(\Bitrix\Main\HttpRequest $request): ?\Bitrix\Main\Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('INTRANET_USER_GRID_ROW_ACTIONS_CONFIRM') ?? '';
	}

	public function isAvailable(array $rawFields): bool
	{
		return CurrentUser::get()->isAdmin()
			&& $rawFields['ACTIVE'] === 'N'
			&& !empty($rawFields['CONFIRM_CODE'])
			&& $rawFields['ID'] !== CurrentUser::get()->getId();
	}

	public function getExtensionMethod(): string
	{
		return 'confirmAction';
	}

	protected function getActionParams(array $rawFields): array
	{
		return [
			'isAccept' => true,
			'userId' => $rawFields['ID'],
		];
	}
}