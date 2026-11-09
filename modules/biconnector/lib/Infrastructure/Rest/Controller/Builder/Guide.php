<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\BuilderOperationSupport;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\GuideDto;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\GuideUnavailableException;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Guide\GetGuideRequest;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Guide\GetGuideOperation;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\MethodNotFoundException;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * biconnector.builder.guide.get: the agent guide of BI Builder, the same text get_bi_guide answers.
 */
#[DtoType(GuideDto::class)]
final class Guide extends AbstractBuilderController
{
	use BuilderOperationSupport;

	public const FEATURE_FLAG = FlagGate::BUILDER_AGENT_SKILL;

	// Keys of the refusal data the client may see: the requested section and the allowed ids.
	private const REFUSAL_DATA_KEYS = ['section', 'allowed'];

	/**
	 * While the flag of the feature is off the method answers not found before the quota is taken.
	 */
	protected function processBeforeAction(Action $action): bool
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if (!$this->createFlagGate()->isEnabled(self::FEATURE_FLAG, $userId))
		{
			throw new MethodNotFoundException($this->getMethodDescription()?->actionUri ?? $action->getName());
		}

		return parent::processBeforeAction($action);
	}

	/**
	 * Without `section` the general rules and the table of contents (`rules`, `start`, `sections`); with it
	 * the section (`id`, `title`, `text`, `next`). Both carry `revision` and `language`.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[FailureCodes(
		FailureCode::InvalidArgument,
		FailureCode::NotFound,
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::RateLimit,
	)]
	public function getAction(GetGuideRequest $request): ArrayResponse
	{
		$this->createActor();

		$section = $request->section ?? null;
		$result = (new GetGuideOperation())->execute($section, Loc::getCurrentLang());
		if ($section === null && $this->isUnavailable($result))
		{
			// The refusal names no section, so the factory would take it for an unavailable dashboard.
			throw new GuideUnavailableException();
		}

		return new ArrayResponse($this->requireResultData($this->withPublicData($result)));
	}

	private function isUnavailable(Result $result): bool
	{
		$error = $result->getErrors()[0] ?? null;

		return $error !== null && $error->getCode() === FailureCode::Unavailable->value;
	}

	/**
	 * A refusal keeps its first error and only the data keys the REST answer names.
	 */
	private function withPublicData(Result $result): Result
	{
		if ($result->isSuccess())
		{
			return $result;
		}

		return (new Result())
			->addError($result->getErrors()[0])
			->setData(array_intersect_key($result->getData(), array_flip(self::REFUSAL_DATA_KEYS)))
		;
	}
}
