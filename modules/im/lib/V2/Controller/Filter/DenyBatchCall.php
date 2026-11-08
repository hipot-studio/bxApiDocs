<?php

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Denies an action called inside a REST batch. An action that answers with a file body terminates
 * the process while the batch response is only half written, so the refusal has to happen before
 * the action starts. Inside a batch the REST server of the sub-call is a CRestServerBatchItem,
 * and a plain call brings a CRestServer: checking the base class would block the method for good.
 */
class DenyBatchCall extends Base
{
	public function onBeforeAction(Event $event)
	{
		foreach ($this->getAction()->getArguments() as $argument)
		{
			if ($argument instanceof \CRestServerBatchItem)
			{
				$this->addError(new FileError(FileError::BATCH_NOT_ALLOWED));

				return new EventResult(EventResult::ERROR, null, null, $this);
			}
		}

		return null;
	}
}
