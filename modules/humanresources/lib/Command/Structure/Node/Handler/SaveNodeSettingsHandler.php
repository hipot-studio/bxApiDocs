<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Command\Structure\Node\Handler;

use Bitrix\HumanResources\Command\Structure\Node\SaveNodeSettingsCommand;
use Bitrix\HumanResources\Item\Collection\NodeSettingsCollection;
use Bitrix\HumanResources\Item\NodeSettings;
use Bitrix\HumanResources\Repository\NodeSettingsRepository;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeSettingsType;
use Bitrix\Main\Result;

class SaveNodeSettingsHandler
{
	private NodeSettingsRepository $nodeSettingsRepository;

	public function __construct()
	{
		$this->nodeSettingsRepository = Container::getNodeSettingsRepository();
	}

	public function __invoke(SaveNodeSettingsCommand $command): Result
	{
		foreach ($command->settings as $type => $settings)
		{
			$settingsCollection = new NodeSettingsCollection();
			if ($settings['replace'] ?? false)
			{
				$this->nodeSettingsRepository->removeByTypeAndNodeId(
					$command->node->id,
					NodeSettingsType::from($type),
				);
			}

			foreach ($settings['values'] as $value)
			{
				$settingsCollection->add(
					new NodeSettings(
						$command->node->id,
						NodeSettingsType::from($type),
						$value,
					),
				);
			}

			$this->nodeSettingsRepository->createByCollection($settingsCollection);
		}

		return new Result();
	}
}
