<?php

namespace Bitrix\Im\V2\Controller\Chat;

use Bitrix\Im\V2\Analytics\ChatAnalytics;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Controller\Chat\Dto\StructureLinkFieldsDto;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Integration\HumanResources;
use Bitrix\Im\V2\Permission;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Loader;

class Structure extends BaseController
{
	public const MAX_NODES_PER_REQUEST = 10;

	public function configureActions()
	{
		return [
			'link' => [
				'+prefilters' => [
					new CheckActionAccess(Permission\Action::LinkStructureNode),
				],
			],
		];
	}

	public function getAutoWiredParameters()
	{
		return array_merge(
			parent::getAutoWiredParameters(),
			[
				new ExactParameter(
					StructureLinkFieldsDto::class,
					'fields',
					function ($className, array $entities = []) {
						return StructureLinkFieldsDto::create(['entities' => $entities]);
					}
				),
			]
		);
	}

	/**
	 * @restMethod im.v2.Chat.Structure.link
	 */
	public function linkAction(Chat $chat, StructureLinkFieldsDto $fields): ?array
	{
		$entities = $fields->getEntities();
		if ($entities === [])
		{
			return ['result' => true];
		}

		if (!HumanResources\Structure::isValidEntityList($entities))
		{
			$this->addError(new HumanResources\Error(HumanResources\Error::MALFORMED_ENTITY_LIST));

			return null;
		}

		// counted before the list is split: splitting converts access codes and must not be fed an
		// unbounded list
		if (count($entities) > self::MAX_NODES_PER_REQUEST)
		{
			$this->addError(new HumanResources\Error(HumanResources\Error::TOO_MANY_NODES));

			return null;
		}

		if (!Loader::includeModule('humanresources'))
		{
			$this->addError(new HumanResources\Error(HumanResources\Error::STRUCTURE_NOT_AVAILABLE));

			return null;
		}

		// a chat type out of scope has no row in the rights matrix, so the prefilter lets its members
		// through and the type is answered here: the client must tell "not for this chat" apart from
		// "not for you". The type is read as the extended one, the way the matrix itself is keyed:
		// the raw one puts a videoconference and a project chat next to an ordinary chat.
		if (!HumanResources\Structure::isChatTypeSupported($chat))
		{
			$this->addError(new HumanResources\Error(HumanResources\Error::CHAT_TYPE_NOT_SUPPORTED));

			return null;
		}

		[$users, $nodes] = HumanResources\Structure::splitEntities($entities);
		if (!empty($users) || empty($nodes))
		{
			$this->addError(new HumanResources\Error(HumanResources\Error::MALFORMED_ENTITY_LIST));

			return null;
		}

		if (!HumanResources\Structure::areNodeKindsAvailable($nodes))
		{
			$this->addError(new HumanResources\Error(HumanResources\Error::STRUCTURE_NOT_AVAILABLE));

			return null;
		}

		$structure = new HumanResources\Structure($chat);

		if (!$structure->areNodesVisible($nodes))
		{
			$this->addError(new HumanResources\Error(HumanResources\Error::NODE_NOT_AVAILABLE));

			return null;
		}

		$freshNodes = $structure->filterAlreadyLinked($nodes);
		if ($freshNodes === [])
		{
			return ['result' => true];
		}

		$result = $structure->link($freshNodes);

		// The event goes by what was linked, not by what was asked for, and it goes before the answer:
		// the nodes are linked one by one, a refusal on one of them leaves the earlier ones linked, and
		// a repeat of the same selection would drop them as already linked - their event would be lost
		// for good.
		$this->sendAddDepartmentAnalytics($chat, $result->getResult() ?? []);

		if (!$result->isSuccess())
		{
			$this->addError($result->getErrors()[0]);

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @param list<string> $linkedNodes
	 */
	private function sendAddDepartmentAnalytics(Chat $chat, array $linkedNodes): void
	{
		if ($linkedNodes === [])
		{
			return;
		}

		$analytics = new ChatAnalytics($chat);
		foreach ($linkedNodes as $node)
		{
			$analytics->addAddDepartment(ChatAnalytics::SECTION_CHAT_ADD_POPUP, ChatAnalytics::ELEMENT_PLUS_BUTTON);
		}
	}
}
