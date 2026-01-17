<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Template\CRM;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Provider\CrmItemProvider;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;

class Item extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Template.CRM.Item.list
	 */
	#[CloseSession]
	public function listAction(
		#[Permission\Read]
		Entity\Template $template,
		CrmItemProvider $crmItemProvider,
	): ?CrmItemCollection
	{
		return $crmItemProvider->getByIdsByTemplateId(
			ids: (array)$template->crmItemIds,
			templateId: $template->id,
			userId: $this->userId,
		);
	}
}
