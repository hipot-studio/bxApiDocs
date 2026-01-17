<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\Config\DeleteConfig;

class FullDeleteRelations
{
	public function __construct(
		private readonly DeleteConfig $config,
	)
	{

	}

	public function __invoke(array $template): void
	{
		(new DeleteSystemLog())($template);

		(new DeleteLegacyFiles())($template);

		(new DeleteChecklists())($template);

		(new DeleteRights())($template);

		(new DeleteMembers())($template);

		(new DeleteTags())($template);

		(new DeleteTemplateDependencies())($template);

		(new DeleteScenario())($template);

		(new DeleteSubTemplates($this->config))($template);

		(new DeleteSubTree())($template);

		(new DeleteUserFields())($template);
	}
}
