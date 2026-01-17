<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Internal\Service\OptionDictionary;
use Bitrix\Tasks\V2\Public\Command\User\SetUserOptionCommand;

class Option extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Option.set
	 */
	#[CloseSession]
	public function setAction(
		OptionDictionary $optionName,
		string $value,
	): ?array
	{
		$result = (new SetUserOptionCommand(
			optionName: $optionName,
			optionValue: $value,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [];
	}

	/**
	 * @ajaxAction tasks.V2.Option.setBool
	 */
	#[CloseSession]
	public function setBoolAction(
		OptionDictionary $optionName,
		bool $value,
	): ?array
	{
		$result = (new SetUserOptionCommand(
			optionName: $optionName,
			optionValue: $value ? 'true' : 'false',
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [];
	}
}
