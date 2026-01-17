<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Handler\Exception\TemplateFieldValidateException;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;

class PrepareParentId implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		if (!isset($fields['PARENT_ID']))
		{
			return $fields;
		}

		if ((int)$fields['PARENT_ID'] <= 0)
		{
			$fields['PARENT_ID'] = false;

			return $fields;
		}

		$parentTask = TaskRegistry::getInstance()->get((int)$fields['PARENT_ID']);
		if (!$parentTask)
		{
			throw new TemplateFieldValidateException(Loc::getMessage('TASKS_BAD_PARENT_ID'));
		}

		$fields['BASE_TEMPLATE_ID'] = 0;

		return $fields;
	}

}
