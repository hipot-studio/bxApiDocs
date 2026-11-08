<?php

namespace Bitrix\Sign\Operation\Document\Validation;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Service\Sign\DocumentService;

final class ValidateInviteMessage implements Contract\Operation
{
	public const MAX_LENGTH = 2000;

	private const ERROR_CODE_MESSAGE_TOO_LONG = 'MESSAGE_TOO_LONG';

	public function __construct(
		private readonly ?string $message,
	)
	{
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();

		// The limit belongs to the value that gets stored, and every entry point stores the normalized
		// one. Counting the raw input instead would refuse a text of 2000 significant characters padded
		// with spaces, which the client counts as fitting and the write would store as fitting.
		$message = DocumentService::normalizeInviteMessage($this->message);
		if ($message === null)
		{
			return $result;
		}

		if (mb_strlen($message) > self::MAX_LENGTH)
		{
			return $result->addError(new Main\Error(
				Loc::getMessage('SIGN_OPERATION_DOCUMENT_VALIDATION_INVITE_MESSAGE_TOO_LONG', [
					'#MAX_LENGTH#' => self::MAX_LENGTH,
				]),
				self::ERROR_CODE_MESSAGE_TOO_LONG,
			));
		}

		return $result;
	}
}
