<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare;

use Bitrix\Tasks\Control\Handler\Exception\TemplateFieldValidateException;
use CTaskTemplates;

class PrepareType implements PrepareFieldInterface
{
	public function __invoke(array $fields): array
	{
		if (($fields['TPARAM_TYPE'] ?? null) && (int)$fields['TPARAM_TYPE'] !== CTaskTemplates::TYPE_FOR_NEW_USER) // todo
		{
			throw new TemplateFieldValidateException('Unknown template type id passed');
		}

		if ((int)($fields['TPARAM_TYPE'] ?? null) === CTaskTemplates::TYPE_FOR_NEW_USER)
		{
			$fields['BASE_TEMPLATE_ID'] = '';
			$fields['REPLICATE_PARAMS'] = [];
			$fields['RESPONSIBLE_ID'] = '0';
			$fields['RESPONSIBLES'] = [0];
			$fields['MULTITASK'] = 'N';
		}

		return $fields;
	}
}
