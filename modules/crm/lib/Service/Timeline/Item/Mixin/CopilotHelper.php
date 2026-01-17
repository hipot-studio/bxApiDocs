<?php

namespace Bitrix\Crm\Service\Timeline\Item\Mixin;

use Bitrix\Crm\Badge\Badge;
use Bitrix\Crm\Badge\SourceIdentifier;
use Bitrix\Crm\Badge\Type\AiCallFieldsFillingResult;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\OperationState;
use Bitrix\Crm\Integration\AI\Operation\Orchestrator;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Layout\Header\Tag;
use Bitrix\Crm\Settings\Crm;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;

trait CopilotHelper
{
	final public function isItemHashValid(int $activityId, Context $context): bool
	{
		return (new Orchestrator())->findPossibleFillFieldsTarget($activityId)?->getHash() === $context->getIdentifier()->getHash();
	}

	final public function fillAILicenceAttributes(): self
	{
		$this->props = [
			'data-activity-id' => $this->activityId,
		];

		if (!AIManager::isAILicenceAccepted($this->context->getUserId()))
		{
			if (Crm::isBox())
			{
				$this->jsEventAction->addActionParamBoolean('isCopilotAgreementNeedShow', true);
			}
			elseif (!$this->context->getUserPermissions()->isAdmin())
			{
				$this->props = [
					'data-bitrix24-license-feature' => AIManager::AI_LICENCE_FEATURE_NAME,
				];
			}
		}

		return $this;
	}

	final public function isCopilotScope(): bool
	{
		return AIManager::isAiCallProcessingEnabled()
			&& in_array(
				$this->getContext()->getEntityTypeId(),
				AIManager::SUPPORTED_ENTITY_TYPE_IDS,
				true
			)
		;
	}

	final public function getAiTags(int $entityTypeId, int $entityId, int $activityId): array
	{
		$result = [];

		if (
			$this->isCopilotScope()
			&& (new OperationState($activityId, $entityTypeId, $entityId))->isFillFieldsScenarioSuccess()
		)
		{
			$result['copilotDone'] = (new Tag(
				Loc::getMessage('CRM_TIMELINE_TAG_COPILOT_DONE'),
				Tag::TYPE_LAVENDER
			))->setScopeWeb();

			return $result;
		}

		$limitExceededBadge = Container::getInstance()->getBadge(
			Badge::AI_FIELDS_FILLING_RESULT,
			AiCallFieldsFillingResult::ERROR_LIMIT_EXCEEDED,
		);

		$itemIdentifier = $this->getContext()->getIdentifier();
		$sourceIdentifier = new SourceIdentifier(
			SourceIdentifier::CRM_OWNER_TYPE_PROVIDER,
			CCrmOwnerType::Activity,
			$activityId,
		);

		if ($limitExceededBadge->isBound($itemIdentifier, $sourceIdentifier))
		{
			$result['copilotLimitExceeded'] = (new Tag(
				AiCallFieldsFillingResult::getLimitExceededTextValue(),
				Tag::TYPE_FAILURE,
			))->setScopeWeb();
		}

		return $result;
	}
}
