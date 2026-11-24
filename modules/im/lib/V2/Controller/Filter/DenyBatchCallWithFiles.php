<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Denies the batch form of a file upload called inside a REST batch, and only that.
 *
 * The limits of a batch count bytes, and by bytes the door adds no worst case: the sum of a batch may
 * not exceed the ceiling one file already has. By operations it does. A REST batch carries up to
 * CRestUtil::BATCH_MAX_LENGTH sub-calls, so one HTTP request that used to create at most 50 disk files
 * and 50 messages would create up to 500 disk files at the same body size - a tenfold amplification of
 * disk and database work that neither limit of the batch can see, because the body stays small and
 * post_max_size is never reached. Refusing the composition is cheaper than budgeting it.
 *
 * 🔴 The single form inside a REST batch keeps working exactly as it does today: it is the form that
 * has callers, and taking it away would be a breaking change of a door nobody asked to change. Hence
 * the two conditions below, both required - a call inside a batch AND a request carrying files.
 *
 * Finding the batch: inside one, the server of the sub-call is a CRestServerBatchItem, while a plain
 * call brings a CRestServer, so checking the base class would block the method for good (the same
 * reasoning as in DenyBatchCall). The upload actions declare no server parameter of their own, so the
 * server is read where the REST engine puts it - among the source parameters of the controller.
 *
 * @see \Bitrix\Im\V2\Controller\Filter\DenyBatchCall the filter that refuses a call inside a batch outright
 * @see \Bitrix\Rest\Engine\RestManager::processMethodRequest the place __restServer comes from
 */
class DenyBatchCallWithFiles extends Base
{
	public function onBeforeAction(Event $event)
	{
		// the cheap half first: a plain call leaves the filter without a single query of its own
		if (!$this->isInsideRestBatch() || !$this->carriesFilesForm())
		{
			return null;
		}

		$this->addError(new FileError(FileError::BATCH_NOT_ALLOWED));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	private function isInsideRestBatch(): bool
	{
		foreach ($this->getAction()->getController()->getSourceParametersList() as $source)
		{
			if (is_array($source) && (($source['__restServer'] ?? null) instanceof \CRestServerBatchItem))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The very discriminator the actions use - isset($fields['files']), character for character - read off
	 * the arguments the action will get, so the filter and the action can never disagree about which form
	 * the request is in.
	 *
	 * The predicate is deliberately not !empty(): an empty fields.files still sends the action into its
	 * batch branch, and a filter that let such a request through would be describing a form the action
	 * does not see. Refusing it here costs nothing - the action would refuse it a step later anyway.
	 */
	private function carriesFilesForm(): bool
	{
		$fields = $this->getAction()->getArguments()['fields'] ?? null;

		return is_array($fields) && isset($fields['files']);
	}
}
