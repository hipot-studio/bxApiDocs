<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Handler\Exception\TemplateFieldValidateException;
use CTaskTemplates;
use CUser;

class PrepareResponsible implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		if (
			!isset($fields['RESPONSIBLES'])
			&& !isset($fields['RESPONSIBLE_ID'])
		)
		{
			return $fields;
		}

		if (!isset($fields['RESPONSIBLE_ID']))
		{
			$fields['RESPONSIBLE_ID'] = (int)array_values($fields['RESPONSIBLES'])[0];
		}

		if (
			isset($fields['RESPONSIBLES'])
			&& is_string($fields['RESPONSIBLES'])
		)
		{
			$fields['RESPONSIBLES'] = unserialize($fields['RESPONSIBLES'], ['allowed_classes' => false]);
		}

		if (
			((int)$fullTemplateData['TPARAM_TYPE'] !== CTaskTemplates::TYPE_FOR_NEW_USER)
			&& isset($fields["RESPONSIBLE_ID"])
		)
		{
			$r = CUser::GetByID($fields["RESPONSIBLE_ID"]);
			if (!$r->Fetch())
			{
				throw new TemplateFieldValidateException(Loc::getMessage('TASKS_BAD_ASSIGNEE_EX'));
			}
		}

		if (
			empty($fields['RESPONSIBLES'])
			&& empty($fullTemplateData['RESPONSIBLES'])
		)
		{
			$fields['RESPONSIBLES'] = [$fields['RESPONSIBLE_ID']];
		}

		return $fields;
	}
}
