<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Action\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\BuilderAccessDeniedException;
use Bitrix\BIConnector\Infrastructure\Rest\Support\BuilderRestExceptionFactory;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\Main\Result;

/**
 * What every builder action does around its operation: the caller as an actor and the answer made of the
 * operation result. The availability of BI Builder to the caller is checked by the entry point before the
 * action (AbstractBuilderController::processBeforeAction()), so an action takes createActor().
 *
 * Used by the builder controllers only, which provide requireResultData(), getCurrentUser() and
 * createAvailabilityCheck().
 */
trait BuilderOperationSupport
{
	/**
	 * The caller once BI Builder is available to them, asked of the same check the entry point asks. Only
	 * for a call the entry point has not checked; an action of a builder controller takes createActor().
	 */
	protected function createAvailableActor(): Actor
	{
		$availability = $this->createAvailabilityCheck()->check((int)$this->getCurrentUser()?->getId());
		if (!$availability->isSuccess())
		{
			throw BuilderRestExceptionFactory::from($availability);
		}

		return $this->createActor();
	}

	/**
	 * The caller of a builder action; BI Builder is already known to be available to them.
	 */
	protected function createActor(): Actor
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if ($userId <= 0)
		{
			throw new BuilderAccessDeniedException();
		}

		return new Actor($userId, Channel::Rest);
	}

	/**
	 * Data of a successful operation. A refusal is rebuilt from the code and the text of its first error
	 * only: whatever else the operation keeps in the result never reaches the client.
	 *
	 * @param int|null $entityId The record the request addresses; a NotFound refusal names it.
	 */
	protected function answer(Result $result, ?int $entityId = null): array
	{
		if ($result->isSuccess())
		{
			return $result->getData();
		}

		$refusal = (new Result())->addError($result->getErrors()[0]);

		return $this->requireResultData($refusal, $entityId);
	}
}
