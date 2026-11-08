<?php

namespace Bitrix\Rest\V3\Access;

use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;
use Bitrix\Main\UserField\UserFieldAccess;
use Bitrix\Rest\V3\Dto\Mapping\MapperRegistry;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Realisation\Dto\Mapping\CustomMapper;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use CUserFieldEnum;

/**
 * Reference provider on top of the user field ACL of the module that owns the resource.
 *
 * It holds no policy of its own: it takes the ACL implementation the module declared in its
 * configuration and asks it the question that matches the action. A module that declares no
 * ACL is refused, so a missing declaration reads as "no access" instead of a broken request.
 *
 * Known limit on the field dictionary. The ACL speaks about user field entities, and the schema
 * methods field.list / field.get have none behind them: they return the field dictionary of the
 * resource DTO, not user field data, so the context carries no entity id for them. The question
 * asked here is then canReadWithFilter([]), which the ACL widens to every entity of the module,
 * that is: "may the user read the user fields of at least one entity of this module". Resources
 * of the same module are not told apart by it, and the write methods, which do carry an entity
 * id, are narrowed while the dictionary reads are not. A module that has to gate the dictionary
 * per resource overrides this provider and decides by $context->resourceDtoFqcn.
 *
 * The enum value lookup is a separate method so that a test can drive the decision without portal data.
 */
class UserFieldAccessProvider implements FieldAccessProvider
{
	public const ERROR_ACCESS_NOT_DECLARED = 'USER_FIELD_ACCESS_NOT_DECLARED';
	public const ERROR_ACCESS_DENIED = 'USER_FIELD_ACCESS_DENIED';

	private const ENUM_ROUTE_MARKER = '.field.custom.enum.';

	public function decide(FieldAccessContext $context): Result
	{
		$moduleId = $context->module;

		try
		{
			$access = UserFieldAccess::getInstance($moduleId, $context->userId);
		}
		catch (ObjectNotFoundException | LoaderException)
		{
			return (new Result())->addError(new Error(
				"Module '{$moduleId}' declares no user field access implementation.",
				self::ERROR_ACCESS_NOT_DECLARED,
			));
		}

		if ($this->isAllowed($access, $context))
		{
			return new Result();
		}

		return (new Result())->addError(new Error(
			"Action '{$context->action}' on the fields of '{$context->actionUri}' is not allowed.",
			self::ERROR_ACCESS_DENIED,
		));
	}

	private function isAllowed(UserFieldAccess $access, FieldAccessContext $context): bool
	{
		if (str_contains($context->actionUri, self::ENUM_ROUTE_MARKER))
		{
			return $this->isEnumValueActionAllowed($access, $context);
		}

		return $this->isUserFieldActionAllowed($access, $context);
	}

	private function isUserFieldActionAllowed(UserFieldAccess $access, FieldAccessContext $context): bool
	{
		$userFieldId = $this->resolveRequestedId($context);

		return match ($context->action)
		{
			'list' => $access->canReadWithFilter($this->buildEntityFilter($context)),
			'get' => $userFieldId === null
				? $access->canReadWithFilter($this->buildEntityFilter($context))
				: $access->canRead($userFieldId),
			'add' => $this->isAddAllowed($access, $context),
			'update' => $userFieldId !== null && $access->canUpdate($userFieldId),
			'delete' => $userFieldId !== null && $access->canDelete($userFieldId),
			default => false,
		};
	}

	/**
	 * An enum route addresses a list value, not a user field: the two are numbered independently,
	 * so the ACL has to be asked about the field that owns the value. A value whose owner cannot
	 * be resolved is refused instead of being let through.
	 */
	private function isEnumValueActionAllowed(UserFieldAccess $access, FieldAccessContext $context): bool
	{
		// list addresses the entity of the route, not a single value
		if ($context->action === 'list')
		{
			return $access->canReadWithFilter($this->buildEntityFilter($context));
		}

		// add does not create a field: it writes the list of an existing one, so the ACL is
		// asked about changing that field and not about adding a field to the entity
		if ($context->action === 'add')
		{
			$targetFieldId = $this->resolveEnumTargetFieldId($context);

			return $targetFieldId !== null && $access->canUpdate($targetFieldId);
		}

		$ownerFieldId = $this->resolveEnumValueOwnerFieldId($context);
		if ($ownerFieldId === null)
		{
			return false;
		}

		return match ($context->action)
		{
			'get' => $access->canRead($ownerFieldId),
			'update' => $access->canUpdate($ownerFieldId),
			'delete' => $access->canDelete($ownerFieldId),
			default => false,
		};
	}

	/**
	 * The ACL speaks about the user field entity, and every question it answers is narrowed by it.
	 * An absent entity id leaves the whole set of entities available to the user.
	 */
	private function buildEntityFilter(FieldAccessContext $context): array
	{
		return $context->entityId === null ? [] : ['ENTITY_ID' => $context->entityId];
	}

	private function isAddAllowed(UserFieldAccess $access, FieldAccessContext $context): bool
	{
		$fieldToAdd = $this->buildFieldToAdd($context);

		return $fieldToAdd !== null && $access->canAdd($fieldToAdd);
	}

	/**
	 * The ACL decides an add by the description of the field, and a module reads whichever part of
	 * it its own policy is about: the core refuses a restricted USER_TYPE_ID, crm additionally
	 * refuses a reserved FIELD_NAME of an entity whose fields are created lazily. A description
	 * narrowed to the keys the base ACL happens to read would therefore let a module allow a field
	 * it forbids, so the description is built by the mapper of the write operation and reaches the
	 * ACL in the shape CUserTypeEntity::Add receives.
	 *
	 * The mapper is asked for the raw items of the request and not for the dto of the action: the
	 * dto is produced by a validating conversion, and running it here would answer an unauthorized
	 * caller with a validation error instead of a refusal.
	 *
	 * A request whose description cannot be built is refused: an add is the one action whose object
	 * exists only in the request, so there is nothing else left to ask the ACL about.
	 */
	private function buildFieldToAdd(FieldAccessContext $context): ?array
	{
		$request = $this->findRequest($context);
		if ($request === null || !isset($request->fields) || !($request->fields instanceof FieldsStructure))
		{
			return null;
		}

		$mapper = $context->dtoFqcn === null ? null : MapperRegistry::getForDto($context->dtoFqcn);
		if (!($mapper instanceof CustomMapper))
		{
			return null;
		}

		$field = $mapper->getValuesForAddFromItems($request->fields->getItems());

		return isset($field['ENTITY_ID']) ? $field : null;
	}

	/**
	 * The field whose list of values an enum add writes to: the request carries its id in the
	 * fields structure, the same value the action itself looks the field up by.
	 */
	private function resolveEnumTargetFieldId(FieldAccessContext $context): ?int
	{
		$request = $this->findRequest($context);
		if ($request === null)
		{
			return null;
		}

		if (isset($request->fields) && $request->fields instanceof FieldsStructure)
		{
			$fieldId = (int)($request->fields->getItems()['fieldId'] ?? 0);

			return $fieldId > 0 ? $fieldId : null;
		}

		return null;
	}

	private function resolveRequestedId(FieldAccessContext $context): ?int
	{
		$request = $this->findRequest($context);
		$requestedId = isset($request->id) ? (int)$request->id : 0;

		return $requestedId > 0 ? $requestedId : null;
	}

	private function resolveEnumValueOwnerFieldId(FieldAccessContext $context): ?int
	{
		$enumValueId = $this->resolveRequestedId($context);

		return $enumValueId === null ? null : $this->findEnumValueOwnerFieldId($enumValueId);
	}

	protected function findEnumValueOwnerFieldId(int $enumValueId): ?int
	{
		$enumValue = CUserFieldEnum::GetList([], ['ID' => $enumValueId])->Fetch();
		if (!is_array($enumValue))
		{
			return null;
		}

		$ownerFieldId = (int)($enumValue['USER_FIELD_ID'] ?? 0);

		return $ownerFieldId > 0 ? $ownerFieldId : null;
	}

	private function findRequest(FieldAccessContext $context): ?Request
	{
		foreach ($context->arguments as $argument)
		{
			if ($argument instanceof Request)
			{
				return $argument;
			}
		}

		return null;
	}
}
