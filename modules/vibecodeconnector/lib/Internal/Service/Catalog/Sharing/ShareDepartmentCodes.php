<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Sharing;

use Bitrix\Main\UI\EntitySelector\Converter;

/**
 * Translates department choices of the sharing dialog to Bitrix24 access codes and back.
 *
 * Dialog id `7` means the department with subdepartments (`DR7`), `7:F` means the department only (`D7`).
 */
final class ShareDepartmentCodes
{
	private const ENTITY_DEPARTMENT = 'department';
	private const DIALOG_ID_PATTERN = '/^[1-9][0-9]*(:F)?$/D';
	private const ACCESS_CODE_PATTERN = '/^DR?[1-9][0-9]*$/D';

	public function dialogIdToAccessCode(string $dialogId): ?string
	{
		if (preg_match(self::DIALOG_ID_PATTERN, $dialogId) !== 1)
		{
			return null;
		}

		$codes = Converter::convertToFinderCodes([[self::ENTITY_DEPARTMENT, $dialogId]]);
		$code = $codes[0] ?? null;

		return is_string($code) && preg_match(self::ACCESS_CODE_PATTERN, $code) === 1 ? $code : null;
	}

	public function accessCodeToDialogId(string $code): ?string
	{
		if (preg_match(self::ACCESS_CODE_PATTERN, $code) !== 1)
		{
			return null;
		}

		foreach (Converter::convertFromFinderCodes([$code]) as $entity)
		{
			if (($entity[0] ?? null) === self::ENTITY_DEPARTMENT && is_string($entity[1] ?? null))
			{
				return $entity[1];
			}
		}

		return null;
	}
}
