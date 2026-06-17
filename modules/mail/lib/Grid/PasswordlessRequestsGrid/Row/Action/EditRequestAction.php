<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Action;

use Bitrix\Mail\Helper\Dto\PasswordlessRequestsGrid\PasswordlessRequestRowDto;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class EditRequestAction extends JsGridAction
{
	public static function getId(): ?string
	{
		return 'edit_passwordless_request';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('MAIL_PASSWORDLESS_GRID_ACTION_EDIT') ?? '';
	}

	public function getActionId(): string
	{
		return 'editRequestAction';
	}

	/**
	 * @return array{mailboxId: int}
	 */
	protected function getActionParams(PasswordlessRequestRowDto $dto): array
	{
		return [
			'mailboxId' => $dto->id,
		];
	}

	public function isEnabled(PasswordlessRequestRowDto $dto): bool
	{
		return true;
	}
}
