<?php

namespace Bitrix\HumanResources\Engine;

use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main;
use Bitrix\Main\Loader;

abstract class Controller extends Main\Engine\Controller
{
	protected function processBeforeAction(Main\Engine\Action $action): bool
	{
		if (!Storage::instance()->isCompanyStructureConverted())
		{
			$this->addError(new Main\Error(
				'Structure has not been converted yet',
				'STRUCTURE_IS_NOT_CONVERTED'
			));

			return false;
		}

		if (!Loader::includeModule('intranet'))
		{
			$this->addError(new Main\Error('Module intranet is not installed'));

			return false;
		}

		return parent::processBeforeAction($action);
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new Main\Engine\AutoWire\ExactParameter(
				Item\Structure::class,
				'structure',
				function ($className, ?int $structureId = null): ?Item\Structure
				{
					$structureRepository = Container::getStructureRepository();

					if (!$structureId)
					{
						return $structureRepository->getByXmlId(Item\Structure::DEFAULT_STRUCTURE_XML_ID);
					}

					return $structureRepository->getById($structureId);
				}
			),
			new Main\Engine\AutoWire\ExactParameter(
				Item\Node::class,
				'node',
				function ($className, $nodeId): ?Item\Node
				{
					return Container::getNodeRepository()->getById($nodeId);
				}
			),
			new Main\Engine\AutoWire\ExactParameter(
				Item\User::class,
				'user',
				function ($className, $userId): ?Item\User
				{
					return Container::getUserRepository()->getById($userId);
				}
			),
			new Main\Engine\AutoWire\ExactParameter(
				Item\NodeMember::class,
				'nodeMember',
				function ($className, $nodeMemberId): ?Item\NodeMember
				{
					return Container::getNodeMemberRepository()->findById($nodeMemberId);
				}
			),
		];
	}
}