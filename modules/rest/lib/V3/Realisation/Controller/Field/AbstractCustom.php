<?php

namespace Bitrix\Rest\V3\Realisation\Controller\Field;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Controller\ValidateDtoTrait;

abstract class AbstractCustom extends RestController
{
	use ValidateDtoTrait;
	protected \CUserTypeManager $customFieldManager;

	protected $customFieldsByEntityIdUserIdLangFieldId = [];
	protected $customFieldsByEntityIdUserIdLangFieldName = [];

	public function __construct(Request $request = null)
	{
		parent::__construct($request);
		$this->customFieldManager = ServiceLocator::getInstance()->get(\CUserTypeManager::class);
	}

	protected function getCustomFieldsByFieldId(string $entityId, int $userId, ?string $lang): array
	{
		if (!isset($this->customFieldsByEntityIdUserIdLangFieldId[$entityId][$userId][$lang]))
		{
			$fields = $this->customFieldManager->getUserFields(
				entity_id: $entityId,
				LANG: ($lang !== null) ? $lang : false,
				user_id: $userId,
			);

			$this->customFieldsByEntityIdUserIdLangFieldName[$entityId][$userId][$lang] = $fields;

			foreach ($fields as $field)
			{
				$this->customFieldsByEntityIdUserIdLangFieldId[$entityId][$userId][$lang][$field['ID']] = $field;
			}
		}

		return $this->customFieldsByEntityIdUserIdLangFieldId[$entityId][$userId][$lang];
	}

	protected function getCustomFieldsByFieldName(string $entityId, int $userId, ?string $lang): array
	{
		if (!isset($this->customFieldsByEntityIdUserIdLangFieldName[$entityId][$userId][$lang]))
		{
			$this->getCustomFieldsByFieldId($entityId, $userId, $lang);
		}

		return $this->customFieldsByEntityIdUserIdLangFieldName[$entityId][$userId][$lang];
	}

	protected function unsetFieldsByEntityId(string $entityId): void
	{
		unset($this->customFieldsByEntityIdUserIdLangFieldId[$entityId]);
		unset($this->customFieldsByEntityIdUserIdLangFieldName[$entityId]);
	}
}