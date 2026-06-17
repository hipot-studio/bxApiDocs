<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Action;

use Bitrix\Mail\Helper\Dto\PasswordlessRequestsGrid\PasswordlessRequestRowDto;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class ResendRequestAction extends JsGridAction
{
	public static function getId(): ?string
	{
		return 'resend_passwordless_request';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('MAIL_PASSWORDLESS_GRID_ACTION_RESEND') ?? '';
	}

	public function getActionId(): string
	{
		return 'resendRequestAction';
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
		return in_array($dto->status, [
			MailboxStatus::Pending,
			MailboxStatus::Canceled,
		], true);
	}
}
