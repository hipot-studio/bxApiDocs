<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\UserField\List;

use Bitrix\Crm\Integration\AiAssistant\Tools\UserField\BaseUserFieldTool;
use Bitrix\Main\UserField\Types\EnumType;

abstract class BaseListUserFieldTool extends BaseUserFieldTool
{
	protected function formatUserFieldList(array $userFieldList): array
	{
		return array_map(
			static function (array $userField)
			{
				$result = [
					'name' => (
						$userField['EDIT_FORM_LABEL']
						?? $userField['LIST_COLUMN_LABEL']
						?? $userField['LIST_FILTER_LABEL']
						?? $userField['FIELD_NAME']
						?? ''
					),
					'type' => $userField['USER_TYPE_ID'] ?? '',
					'isMultiple' => $userField['MULTIPLE'] === 'Y',
				];

				if (isset($userField['USER_TYPE_ID']) && $userField['USER_TYPE_ID'] === EnumType::USER_TYPE_ID)
				{
					$enumList = \Bitrix\Main\UserField\Types\EnumType::getList($userField);

					while ($item = $enumList->Fetch())
					{
						$result['values'][] = [
							'code' => $item['ID'],
							'value' => $item['VALUE'],
						];
					}
				}

				return $result;
			},
			$userFieldList,
		);
	}
}
