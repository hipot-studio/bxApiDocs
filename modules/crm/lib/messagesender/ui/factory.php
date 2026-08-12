<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\MessageSender\Channel\Correspondents\ToRepository;
use Bitrix\Crm\MessageSender\UI\Factory\Registry;
use Bitrix\Crm\Multifield\Type\Phone;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\MessageService\Public\UI\MessageEditor\Channel\To;
use Bitrix\MessageService\Public\UI\MessageEditor\ContentProvider;
use Bitrix\MessageService\Public\UI\MessageEditor\ContentProvider\Copilot;
use Bitrix\MessageService\Public\UI\MessageEditor\ContentProvider\Files;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\MessageService\Public\UI\MessageEditor\Scene;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;

final class Factory
{
	use Singleton;

	private Registry $registry;

	private function __construct()
	{
		$this->registry = ServiceLocator::getInstance()->get(Registry::class);
	}

	public function createEditor(Scene $scene, Context $context): \Bitrix\MessageService\Public\UI\MessageEditor\Editor
	{
		$factory = ServiceLocator::getInstance()->get('messageservice.public.ui.factory');
		$editor = $factory->createEditor($scene, $context);

		$editor->setToList($this->getToList($context));
		$editor->setContentProviders(
			$this->getContentProviders($context, $editor->getViewChannels() ?? [])
		);

		return $editor;
	}

	public function getScene(string $sceneId): ?Scene
	{
		if (!Loader::includeModule('messageservice'))
		{
			return null;
		}

		foreach ($this->registry->getScenes() as $scene)
		{
			if ($scene->getId() === $sceneId)
			{
				return $scene;
			}
		}

		return null;
	}

	/**
	 * @param ViewChannel[] $viewChannels
	 * @return ContentProvider[]
	 */
	private function getContentProviders(Context $context, array $viewChannels): array
	{
		$canSendMessage = !empty(array_filter(
			$viewChannels,
			static fn(ViewChannel $vc): bool => $vc->isConnected()
		));

		return [
			new Files(),
			new Copilot([
				'isLocked' => !AIManager::isEnabledInGlobalSettings(GlobalSetting::MessageSenderEditor),
				'moduleId' => 'crm',
				'category' => Loader::includeModule('ai') ? \Bitrix\AI\SharePrompt\Enums\Category::CRM_MESSAGE_EDITOR->value : null,
				'contextId' => 'crm.messagesender.editor',
			]),
			new Editor\ContentProvider\CrmValues($context),
			new Editor\ContentProvider\SalesCenter($context, $canSendMessage),
			new Editor\ContentProvider\Documents($context),
		];
	}

	private function getToList(Context $context): array
	{
		$entityTypeId = $context->getCustomDataInt('entityTypeId');
		$entityId = $context->getCustomDataInt('entityId');
		$categoryId = $context->getCustomDataInt('categoryId');

		if ($entityTypeId === null || $entityId === null)
		{
			return [];
		}

		$itemIdentifier = ItemIdentifier::createByParams(
			$entityTypeId,
			$entityId,
			$categoryId,
		);

		if (!$itemIdentifier)
		{
			return [];
		}

		$allCrmTo = ToRepository::create($itemIdentifier)
			->setUserId($context->getUserId())
			->getListByType(Phone::ID)
		;

		$titlesMap = $this->getTitleMap($allCrmTo);

		$toList = [];
		foreach ($allCrmTo as $crmTo)
		{
			$address = $crmTo->getAddress();
			$addressSource = $crmTo->getAddressSource();

			$title = $titlesMap[$addressSource->getEntityTypeId()][$addressSource->getEntityId()] ?? '';

			$entityTypeName = (string)\CCrmOwnerType::ResolveName($addressSource->getEntityTypeId());
			$avatar = '/bitrix/js/crm/messagesender/editor/images/' . strtolower($entityTypeName) . '.svg';

			$to = To::fromArray([
				'id' => (string)$address->getId(),
				'value' => $address->getValue(),
				'appearance' => [
					'caption' => $title . ' ' . $address->getValueFormatted(),
					'title' => $title,
					'subtitle' => $address->getValueFormatted() . ', ' . $address->getValueTypeCaption(),
					'avatar' => $avatar,
				],
				'customData' => [
					'addressSource' => [
						'entityTypeId' => $addressSource->getEntityTypeId(),
						'entityId' => $addressSource->getEntityId(),
					],
				],
			]);

			if ($to !== null)
			{
				$toList[] = $to;
			}
		}

		return $toList;
	}

	/**
	 * @param \Bitrix\Crm\MessageSender\Channel\Correspondents\To[] $toList
	 * @return array<int, array<int, string>> [entityTypeId => [entityId => title]]
	 */
	private function getTitleMap(array $toList): array
	{
		$idMap = [];
		foreach ($toList as $to)
		{
			$idMap[$to->getAddressSource()->getEntityTypeId()][$to->getAddressSource()->getEntityId()] = $to->getAddressSource()->getEntityId();
		}

		$addressSourcesData = [];
		foreach ($idMap as $entityTypeId => $entityIds)
		{
			$broker = Container::getInstance()->getEntityBroker($entityTypeId);
			$factory = Container::getInstance()->getFactory($entityTypeId);
			if (!$broker || !$factory || !\CCrmOwnerType::isUseFactoryBasedApproach($entityTypeId))
			{
				continue;
			}

			foreach ($broker->getBunchByIds($entityIds) as $item)
			{
				if ($item instanceof EntityObject)
				{
					$item = $factory->getItemByEntityObject($item);
				}
				if (!($item instanceof Item))
				{
					continue;
				}

				$addressSourcesData[$entityTypeId][$item->getId()] = $item->getHeading();
			}
		}

		return $addressSourcesData;
	}
}
