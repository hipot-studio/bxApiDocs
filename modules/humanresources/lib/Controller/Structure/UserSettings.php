<?php

namespace Bitrix\HumanResources\Controller\Structure;

use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Command\Structure\Node\SaveUserSettingsCommand;
use Bitrix\HumanResources\Engine\Controller;
use Bitrix\HumanResources\Exception\CommandException;
use Bitrix\HumanResources\Exception\CommandValidateException;
use Bitrix\HumanResources\Internals\Attribute;
use Bitrix\HumanResources\Internals\Repository\Structure\UserSettingsRepository;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Type\AccessibleItemType;
use Bitrix\Main\Error;
use Bitrix\Main\Request;

class UserSettings extends Controller
{
	private UserSettingsRepository $userSettingsRepository;

	public function __construct(Request $request = null)
	{
		$this->userSettingsRepository = InternalContainer::getUserSettingsRepository();
		parent::__construct($request);
	}

	/**
	 * @param Item\User $user
	 * @return array
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	#[Attribute\StructureActionAccess(
		permission: StructureActionDictionary::ACTION_DEPARTMENT_SETTINGS_EDIT,
		itemType: AccessibleItemType::NODE,
		itemIdRequestKey: 'nodeId',
	)]
	public function getAction(Item\User $user): array
	{
		return $this->userSettingsRepository->getByUserAndTypes($user->id)->getValues();
	}

	/**
	 * @param Item\User $user
	 * @param array $settings
	 * @return void
	 */
	#[Attribute\StructureActionAccess(
		permission: StructureActionDictionary::ACTION_DEPARTMENT_SETTINGS_EDIT,
		itemType: AccessibleItemType::NODE,
		itemIdRequestKey: 'nodeId',
	)]
	public function saveAction(Item\User $user, array $settings = []): void
	{
		try
		{
			$commandResult = (new SaveUserSettingsCommand(
				$user,
				$settings,
			))->run();

			if (!$commandResult->isSuccess())
			{
				$this->addErrors($commandResult->getErrors());
			}
		}
		catch (CommandException|CommandValidateException $e)
		{
			$this->addError(new Error($e->getMessage()));
		}
	}
}
