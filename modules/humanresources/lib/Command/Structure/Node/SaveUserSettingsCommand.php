<?php

namespace Bitrix\HumanResources\Command\Structure\Node;

use Bitrix\HumanResources\Command\AbstractCommand;
use Bitrix\HumanResources\Command\Structure\Node\Handler\SaveUserSettingsHandler;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Type\UserSettingsType;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class SaveUserSettingsCommand extends AbstractCommand
{
	/**
	 * Constructor.
	 *
	 * @param Item\User $user Target user to apply settings.
	 * @param array $settings Associative array of settings in the following structure:
	 *                       $settings = [UserSettingsType->value => ['replace' => true, 'values' => [] ]];
	 */
	public function __construct(public readonly Item\User $user, public array $settings) {}

	protected function validate(): bool
	{
		foreach ($this->settings as $type => $setting)
		{
			if (!UserSettingsType::tryFrom($type))
			{
				return false;
			}
		}

		return true;
	}

	protected function execute(): Result
	{
		try
		{
			(new SaveUserSettingsHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new Result())->addError(new Error(
				$e->getMessage(),
				$e->getCode(),
			));
		}

		return new Result();
	}
}
