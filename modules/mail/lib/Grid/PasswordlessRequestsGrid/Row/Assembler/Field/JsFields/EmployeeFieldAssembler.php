<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Assembler\Field\JsFields;

use Bitrix\Mail\Helper\Dto\PasswordlessRequestsGrid\PasswordlessRequestRowDto;

class EmployeeFieldAssembler extends JsExtensionFieldAssembler
{
	private const EXTENSION_CLASS_NAME = 'EmployeeField';

	/**
	 * @return array{
	 *     id?: int,
	 *     name?: string,
	 *     avatar?: array{
	 *         src: string,
	 *         width: int,
	 *         height: int,
	 *         size: int,
	 *     },
	 *     pathToProfile?: string,
	 * }
	 */
	protected function getRenderParams(PasswordlessRequestRowDto $dto): array
	{
		if (empty($dto->ownerData))
		{
			return [];
		}

		return [
			...$dto->ownerData,
		];
	}

	protected function getExtensionClassName(): string
	{
		return self::EXTENSION_CLASS_NAME;
	}

	protected function prepareColumnForExport(PasswordlessRequestRowDto $dto): string
	{
		return $dto->ownerData['name'] ?? '';
	}
}
