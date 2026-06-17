<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\UserField\List;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Web\Json;

final class ListEditableUserFieldsLeadTool extends BaseListUserFieldTool
{
	public function getName(): string
	{
		return 'list_editable_userfields_lead';
	}

	public function getDescription(): string
	{
		return <<<HEREDOC
			Returns a list of editable user fields for leads.
			Use it to get the available user fields that can be edited for lead entities in the CRM.
			It will return list of user fields that can be edited for leads.
			Each value consists of field's name, type and flag "isMultiple" that shows whether field can contain multiple values or not.
			For enum fields it will also return codes and names of its values.
		HEREDOC;
	}

	public function getInputSchema(): array
	{
		return [];
	}

	public function canRun(int $userId): bool
	{
		return Container::getInstance()
			->getUserPermissions($userId)
			->entityType()
			->canReadItems(\CCrmOwnerType::Lead)
		;
	}

	protected function innerExecute(): string
	{
		$userFields = $this->getEditableUserFieldList(\CCrmOwnerType::Lead);
		$userFields = $this->formatUserFieldList($userFields);

		return Json::encode($userFields);
	}
}
