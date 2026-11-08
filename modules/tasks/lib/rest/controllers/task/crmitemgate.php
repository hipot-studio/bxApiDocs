<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Rest\Controllers\Task;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Validation\Validator\CrmItemsValidator;

/**
 * Shared gate for the CRM item wire value of the bind entry points: only a concrete element
 * ({ABBR}_{id}) passes, synthetic whole-type and no-binding tokens do not.
 */
final class CrmItemGate
{
	public const ERROR_INVALID_ITEM = 'WRONG_CRM_ITEM';

	public static function isConcreteItem(string $crmItemId): bool
	{
		return (new CrmItemsValidator())->validate([$crmItemId])->isSuccess();
	}

	public static function getInvalidItemError(): Error
	{
		return new Error(Loc::getMessage('TASKS_ACTION_CRM_ITEM_INVALID'), self::ERROR_INVALID_ITEM);
	}
}
