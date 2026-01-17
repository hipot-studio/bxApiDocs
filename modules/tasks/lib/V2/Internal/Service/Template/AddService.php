<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\AddDependencies;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\AddMembers;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\AddParent;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\AddTags;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\AddRights;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\AddScenario;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\EnableReplication;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\ResetCache;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\SendPush;
use Bitrix\Tasks\V2\Internal\Service\Template\Prepare\Add\EntityFieldService;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Entity;

class AddService
{
	public function __construct(
		private readonly TemplateRepositoryInterface $templateRepository,
	)
	{

	}

	public function add(Entity\Template $template, AddConfig $config): array
	{
		$this->loadMessages();

		[$template, $fields] = (new EntityFieldService())->prepare($template, $config);

		$id = $this->templateRepository->save($template);

		$fields['ID'] = $id;

		(new AddMembers())($fields);

		(new AddTags($config))($fields);

		(new AddDependencies())($fields);

		(new AddScenario())($fields);

		(new EnableReplication())($fields);

		(new AddParent())($fields);

		(new ResetCache())($fields);

		(new SendPush($config))($fields);

		(new AddRights($config))($fields);

		$this->templateRepository->invalidate($id);

		$template = $this->templateRepository->getById($id);
		if ($template === null)
		{
			throw new TemplateAddException();
		}

		return [$template, $fields];
	}

	private function loadMessages(): void
	{
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/control/template.php');
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/control/handler/templatefieldhandler.php');
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/control/handler/taskfieldhandler.php');
	}
}
