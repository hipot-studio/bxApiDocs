<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Assembler\Field\JsFields;

use Bitrix\Mail\Helper\Dto\PasswordlessRequestsGrid\PasswordlessRequestRowDto;

class StatusFieldAssembler extends JsExtensionFieldAssembler
{
	private const EXTENSION_CLASS_NAME = 'StatusField';

	/**
	 * @return array{status: string}
	 */
	protected function getRenderParams(PasswordlessRequestRowDto $dto): array
	{
		return [
			'status' => $dto->status->value,
		];
	}

	protected function getExtensionClassName(): string
	{
		return self::EXTENSION_CLASS_NAME;
	}

	protected function prepareColumnForExport(PasswordlessRequestRowDto $dto): string
	{
		return $dto->status->value;
	}
}
