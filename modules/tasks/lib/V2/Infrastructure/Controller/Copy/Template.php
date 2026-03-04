<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Copy;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Copy\Config\CopyConfig;
use Bitrix\Tasks\V2\Public\Command\Template\Copy\CopyTemplateCommand;

class Template extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Copy.Template.copy
	 */
	public function copyAction(
		#[Permission\Read]
		Entity\Template $template,
	): ?Arrayable
	{
		/**
		 * @var Result $result
		 */
		$result = (new CopyTemplateCommand(
			templateData: $template,
			config: new CopyConfig(userId: $this->userId),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getObject();
	}
}
