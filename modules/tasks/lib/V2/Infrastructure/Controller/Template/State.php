<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Template;

use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity\State\StateFlags;
use Bitrix\Tasks\V2\Public\Command\Template\State\SetStateFlagsCommand;

class State extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Template.State.set
	 */
	public function setAction(
		StateFlags $flags,
	): ?bool
	{
		$result = (new SetStateFlagsCommand(
			flags: $flags,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}
}
