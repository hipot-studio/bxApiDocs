<?php

namespace Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes;

use Bitrix\Bizproc\BaseType\IntType;
use Bitrix\Bizproc\Internal\Entity\DocumentField\UserAccess;
use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Internal\Integration\Tasks\Access\ProjectUiSelectorAccessProvider;
use Bitrix\Main\Localization\Loc;

class ProjectType extends IntType implements UserAccess
{
	public static function getName(): string
	{
		return (string)Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_TASKS_DOC_FIELD_TYPES_PROJECT_TYPE_NAME');
	}

	public static function getType(): string
	{
		return 'project';
	}

	public static function isTypeAvailable(): bool
	{
		return isModuleInstalled('tasks') && isModuleInstalled('socialnetwork');
	}

	public function isUserHasAccess(int $userId, mixed $value): Result
	{
		$projectIds = is_scalar($value) ? [(int)$value] : (array)$value;
		$projectIds = array_map(fn($item) => (int)$item, $projectIds);
		$projectIds = array_unique(array_filter($projectIds));
		if (empty($projectIds))
		{
			return Result::createOk();
		}

		return (new ProjectUiSelectorAccessProvider())->isUserHasAccess($userId, $projectIds);
	}
}