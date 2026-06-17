<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Assembler\Field\JsFields;

use Bitrix\Mail\Helper\Dto\PasswordlessRequestsGrid\PasswordlessRequestRowDto;
use Bitrix\Main\Type\DateTime;

class DateSentFieldAssembler extends JsExtensionFieldAssembler
{
	private const EXTENSION_CLASS_NAME = 'DateSentField';

	/**
	 * @return array{timestamp: ?int}
	 */
	protected function getRenderParams(PasswordlessRequestRowDto $dto): array
	{
		return [
			'timestamp' => $dto->dateSent,
		];
	}

	protected function getExtensionClassName(): string
	{
		return self::EXTENSION_CLASS_NAME;
	}

	protected function prepareColumnForExport(PasswordlessRequestRowDto $dto): string
	{
		if ($dto->dateSent === null)
		{
			return '';
		}

		return DateTime::createFromTimestamp($dto->dateSent)->toString();
	}
}
