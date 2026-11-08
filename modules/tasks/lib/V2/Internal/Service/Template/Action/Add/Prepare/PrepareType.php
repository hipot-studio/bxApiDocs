<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Handler\Exception\TemplateFieldValidateException;
use Bitrix\Tasks\V2\Internal\Entity\Template\TypeDictionary;

class PrepareType implements PrepareFieldInterface
{
	public function __invoke(array $fields): array
	{
		if (!isset($fields['TPARAM_TYPE']))
		{
			return $fields;
		}

		$newTemplateType = (int)$fields['TPARAM_TYPE'];
		if (!in_array($newTemplateType, [TypeDictionary::USUAL, TypeDictionary::NEW_USERS], true))
		{
			throw new TemplateFieldValidateException('Unknown template type id passed');
		}

		if ($newTemplateType === TypeDictionary::NEW_USERS)
		{
			if ((int)($fields['BASE_TEMPLATE_ID'] ?? 0) > 0)
			{
				throw new TemplateFieldValidateException(
					Loc::getMessage('TASKS_TEMPLATE_FOR_NEW_USER_CANNOT_BE_SUBTEMPLATE'),
				);
			}

			$fields['BASE_TEMPLATE_ID'] = '';
			$fields['REPLICATE_PARAMS'] = [];
			$fields['RESPONSIBLE_ID'] = '0';
			$fields['RESPONSIBLES'] = [0];
			$fields['MULTITASK'] = 'N';
		}

		return $fields;
	}
}
