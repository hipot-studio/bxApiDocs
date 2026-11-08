<?php

namespace Bitrix\Rest\V3\Realisation\Controller\Field;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Controller\ActionFilter\FieldAccessFilter;
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

	protected function getDefaultPreFilters()
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new FieldAccessFilter();

		return $filters;
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

		return $this->customFieldsByEntityIdUserIdLangFieldId[$entityId][$userId][$lang] ?? [];
	}

	protected function getCustomFieldsByFieldName(string $entityId, int $userId, ?string $lang): array
	{
		if (!isset($this->customFieldsByEntityIdUserIdLangFieldName[$entityId][$userId][$lang]))
		{
			$this->getCustomFieldsByFieldId($entityId, $userId, $lang);
		}

		return $this->customFieldsByEntityIdUserIdLangFieldName[$entityId][$userId][$lang];
	}

	/**
	 * Tells whether the entity holds the field and the field is visible to the user, without building
	 * the map of every field of the entity. The answer repeats what the map would say about this one
	 * field: the row has to belong to the entity, its type has to be registered, and the type has to
	 * admit the user. When the map is already built for the same key it answers from it instead.
	 *
	 * @see \CUserTypeManager::getEntities()
	 */
	protected function isCustomFieldVisibleInEntity(string $entityId, int $fieldId, int $userId, ?string $lang): bool
	{
		if (isset($this->customFieldsByEntityIdUserIdLangFieldName[$entityId][$userId][$lang]))
		{
			return isset($this->customFieldsByEntityIdUserIdLangFieldId[$entityId][$userId][$lang][$fieldId]);
		}

		$filter = [
			'ID' => $fieldId,
			'ENTITY_ID' => $this->normalizeEntityId($entityId),
		];
		if (!empty($lang))
		{
			$filter['LANG'] = $lang;
		}

		$field = \CUserTypeEntity::GetList([], $filter)->Fetch();
		if (!$field)
		{
			return false;
		}

		$userType = $this->customFieldManager->GetUserType($field['USER_TYPE_ID']);
		if (!$userType)
		{
			return false;
		}

		if ($userId !== 0 && is_callable([$userType['CLASS_NAME'], 'checkpermission']))
		{
			return (bool)call_user_func_array([$userType['CLASS_NAME'], 'checkpermission'], [$field, $userId]);
		}

		return true;
	}

	/**
	 * Mirrors the normalization the field manager applies to an entity id before it reaches the query,
	 * so that the targeted lookup asks about the same entity as the map of the whole entity would.
	 *
	 * @see \CUserTypeManager::normalizeId()
	 */
	private function normalizeEntityId(string $entityId): string
	{
		return preg_replace('/[^0-9A-Z_]+/', '', $entityId);
	}

	protected function unsetFieldsByEntityId(string $entityId): void
	{
		unset($this->customFieldsByEntityIdUserIdLangFieldId[$entityId]);
		unset($this->customFieldsByEntityIdUserIdLangFieldName[$entityId]);
	}
}