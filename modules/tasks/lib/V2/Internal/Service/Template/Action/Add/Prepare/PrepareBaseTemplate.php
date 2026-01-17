<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Handler\Exception\TemplateFieldValidateException;
use Bitrix\Tasks\Internals\Task\TemplateTable;

class PrepareBaseTemplate implements PrepareFieldInterface
{
	public function __invoke(array $fields): array
	{
		if (!isset($fields['BASE_TEMPLATE_ID']))
		{
			return $fields;
		}

		if ((int)$fields['BASE_TEMPLATE_ID'] <= 0)
		{
			return $fields;
		}

		$baseTemplate = TemplateTable::getById($fields['BASE_TEMPLATE_ID'])->fetch();

		if (!$baseTemplate)
		{
			throw new TemplateFieldValidateException(Loc::getMessage("TASKS_TEMPLATE_BASE_TEMPLATE_ID_NOT_EXISTS"));
		}

		// you cannot add a template with both PARENT_ID and BASE_TEMPLATE_ID set. BASE_TEMPLATE_ID has greather priority
		$fields['PARENT_ID'] = 0;

		// you cannot add REPLICATE parameters here in case of BASE_TEMPLATE_ID is set
		$fields['REPLICATE'] = 'N';

		$fields['REPLICATE_PARAMS'] = [];

		return $fields;
	}
}
