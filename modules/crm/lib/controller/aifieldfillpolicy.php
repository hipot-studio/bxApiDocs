<?php

declare(strict_types=1);

namespace Bitrix\Crm\Controller;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Ai\FieldFillPolicyService;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

final class AiFieldFillPolicy extends Base
{
	public function configureActions(): array
	{
		$configureActions = parent::configureActions();
		$configureActions['save'] = [
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
				// an internal setting of the field configurator: the controller namespace of crm is published
				// to REST as well, so an action left without a scope of its own is reachable by an application
				new ActionFilter\Scope(ActionFilter\Scope::NOT_REST),
			],
		];

		return $configureActions;
	}

	/**
	 * The very gate the editor offers the switch behind: an admin of the entity type in the category the
	 * editor was opened in - an administrator of an automated solution is one for the smart processes of
	 * their own solution, an administrator of the contractor category is one for that category, and a CRM
	 * admin is one for the rest. The category has to be weighed here for the same reason the editor weighs
	 * it: the contractor branch is reachable by the category alone, so a gate without it would refuse the
	 * save behind a switch the editor has just shown.
	 *
	 * A category named by the client escalates nothing: which category of the entity type is the contractor
	 * one is decided on the server, and the right over it is asked of the acting user.
	 *
	 * The arguments are taken from the action: the engine binds them lazily, so they are readable here even
	 * though the action itself has not run yet. A numeric argument is bound in the shape it arrived in,
	 * hence the casts.
	 */
	protected function processBeforeAction(Action $action): bool
	{
		if (!parent::processBeforeAction($action))
		{
			return false;
		}

		$arguments = $action->getArguments();

		$categoryId = isset($arguments['categoryId']) ? (int)$arguments['categoryId'] : null;
		if ($categoryId !== null && $categoryId < 0)
		{
			$this->addError(
				new Error(
					"Argument 'categoryId' has to be a non-negative integer",
					ErrorCode::INVALID_ARG_VALUE
				)
			);

			return false;
		}

		$entityTypeId = (int)($arguments['entityTypeId'] ?? 0);
		if (!Container::getInstance()->getUserPermissions()->isAdminForEntity($entityTypeId, $categoryId))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return false;
		}

		return true;
	}

	/**
	 * $isEnabled is left untyped on purpose. A form-encoded request carries a flag as a string, the
	 * engine binds the value as it came, and it calls the action through Reflection - a call the
	 * strict_types of this file does not reach - so a declared bool would coerce the "false" of a
	 * browser into true. The spellings are matched instead, {@see self::parseIsEnabled()}.
	 *
	 * $categoryId is declared though the policy is held per user field of an entity type and no action of
	 * this controller reads it: the arguments of an action are the parameters of its method, so a category
	 * left undeclared would never reach the permission gate {@see self::processBeforeAction()}.
	 */
	public function saveAction(int $entityTypeId, string $fieldName, $isEnabled, ?int $categoryId = null): ?array
	{
		$isFillEnabled = $this->parseIsEnabled($isEnabled);
		if ($isFillEnabled === null)
		{
			$this->addError(
				new Error(
					"Argument 'isEnabled' has to be a boolean value",
					ErrorCode::INVALID_ARG_VALUE
				)
			);

			return null;
		}

		$policy = FieldFillPolicyService::getInstance();

		if (!$policy->isEntityTypeAvailable($entityTypeId))
		{
			$this->addError(
				new Error(
					Loc::getMessage(
						'CRM_CONTROLLER_AI_FIELD_FILL_POLICY_ENTITY_TYPE_NOT_SUPPORTED',
						['#COPILOT_NAME#' => AIManager::getCopilotName()],
					),
					ErrorCode::ENTITY_NOT_SUPPORTED
				)
			);

			return null;
		}

		$fieldType = $this->getUserFieldType($entityTypeId, $fieldName);
		if ($fieldType === null)
		{
			$this->addError(
				new Error(
					Loc::getMessage('CRM_CONTROLLER_AI_FIELD_FILL_POLICY_FIELD_NOT_FOUND'),
					ErrorCode::NOT_FOUND
				)
			);

			return null;
		}

		if (!in_array($fieldType, $policy->getSupportedFieldTypes(), true))
		{
			$this->addError(
				new Error(
					Loc::getMessage(
						'CRM_CONTROLLER_AI_FIELD_FILL_POLICY_FIELD_TYPE_NOT_SUPPORTED',
						['#COPILOT_NAME#' => AIManager::getCopilotName()],
					),
					ErrorCode::INVALID_ARG_VALUE
				)
			);

			return null;
		}

		$result = $policy->setFillEnabled($entityTypeId, $fieldName, $isFillEnabled);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'isEnabled' => $isFillEnabled,
		];
	}

	/**
	 * The flag in the spellings a request can carry it in, or null for a value in none of them: an
	 * unrecognized value is refused rather than guessed, because every guess about a policy switch has
	 * the permissive state to fall into.
	 */
	private function parseIsEnabled(mixed $isEnabled): ?bool
	{
		if (is_bool($isEnabled))
		{
			return $isEnabled;
		}

		if (!is_scalar($isEnabled))
		{
			return null;
		}

		return match (mb_strtolower((string)$isEnabled))
		{
			'1', 'true', 'y' => true,
			'0', 'false', 'n' => false,
			default => null,
		};
	}

	private function getUserFieldType(int $entityTypeId, string $fieldName): ?string
	{
		$descriptors = CustomFieldRegistry::getInstance()->getEntityDescriptorsMap(EntityType::fromId($entityTypeId));

		return ($descriptors[$fieldName] ?? null)?->type;
	}
}
