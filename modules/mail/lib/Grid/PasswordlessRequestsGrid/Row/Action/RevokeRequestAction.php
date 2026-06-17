<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Action;

use Bitrix\Mail\Helper\Dto\PasswordlessRequestsGrid\PasswordlessRequestRowDto;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class RevokeRequestAction extends JsGridAction
{
	public static function getId(): ?string
	{
		return 'revoke_passwordless_request';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('MAIL_PASSWORDLESS_GRID_ACTION_REVOKE') ?? '';
	}

	public function getActionId(): string
	{
		return 'revokeRequestAction';
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
		return $dto->status === MailboxStatus::Pending;
	}

	/**
	 * @param array{
	 *     ID: int,
	 *     EMAIL: string,
	 *     USER_ID: int,
	 *     ACTIVE: string,
	 *     OWNER_DATA: array,
	 *     DATE_SENT: ?int,
	 * } $rawFields
	 */
	public function getControl(array $rawFields): ?array
	{
		$dto = PasswordlessRequestRowDto::fromGridRow($rawFields);
		if (!$this->isEnabled($dto))
		{
			return null;
		}

		return parent::getControl($rawFields);
	}
}
