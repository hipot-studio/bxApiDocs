<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Template;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFileCollection;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Provider\DiskFileProvider;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Internal\Entity;

class File extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Template.File.list
	 */
	#[CloseSession]
	public function listAction(
		#[Permission\Read]
		Entity\Template $template,
		#[ElementsType(Type::Integer)]
		array $ids,
		DiskFileProvider $diskFileProvider,
	): DiskFileCollection
	{
		return $diskFileProvider->getTemplateAttachmentsByIds(
			ids: $ids,
			templateId: (int)$template->id,
			userId: $this->userId,
		);
	}
}
