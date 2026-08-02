<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

use Bitrix\Tasks\Control\Handler\Exception\TemplateFieldValidateException;
use Bitrix\Tasks\V2\Internal\Entity\Template\TypeDictionary;

class PrepareType implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		if (!isset($fields['TPARAM_TYPE']))
		{
			return $fields;
		}

		$newTemplateType = (int)$fields['TPARAM_TYPE'];
		$currentTemplateType = $fullTemplateData['TPARAM_TYPE'] ?? null;
		if ($newTemplateType !== (int)$currentTemplateType)
		{
			throw new TemplateFieldValidateException('You can not change TYPE of an existing template');
		}

		if (!in_array($newTemplateType, [TypeDictionary::USUAL, TypeDictionary::NEW_USERS], true))
		{
			throw new TemplateFieldValidateException('Unknown template type id passed');
		}

		if ((int)$currentTemplateType === TypeDictionary::NEW_USERS)
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
