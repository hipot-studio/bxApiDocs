<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Template;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Public\Command\Template\Replication\SetReplicationStateCommand;

class Replication extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Template.Replication.setState
	 */
	public function setStateAction(
		#[Permission\Update]
		Entity\Template $template,
	): bool
	{
		$result = (new SetReplicationStateCommand(
			template: $template,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}
}
