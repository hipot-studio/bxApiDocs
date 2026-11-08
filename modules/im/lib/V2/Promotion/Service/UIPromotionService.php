<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Promotion\Service;

use Bitrix\Im\Common;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Common\SingletonTrait;
use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Im\V2\Promotion\Entity\Promotion;
use Bitrix\Im\V2\Promotion\Entity\PromotionList;
use Bitrix\Im\V2\Promotion\Internals\DeviceType;
use Bitrix\Im\V2\Promotion\Internals\PortalAge;
use Bitrix\Im\V2\Promotion\Internals\PromotionType;
use Bitrix\Im\V2\Promotion\Internals\UserType;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\Tour;
use Bitrix\Pull\Event;

class UIPromotionService implements PromotionServiceInterface
{
	use SingletonTrait;

	private const ONE_MONTH = 3600 * 24 * 30;
	private const ENDLESS_LIFETIME = 0;
	private const VOICE_MESSAGE_PROMO_CUTOFF_DATE_OPTION = 'voice_message_promo_cutoff_date';
	private const VOICE_MESSAGE_PROMO_CUTOFF_DATE_DEFAULT = '2026-08-26';
	private const FOLDER_ALL_ONBOARDING_PROMO_CUTOFF_DATE_OPTION = 'folder_all_onboarding_promo_cutoff_date';
	private const FOLDER_ALL_ONBOARDING_PROMO_CUTOFF_DATE_DEFAULT = '2026-10-13';

	public function getActive(DeviceType $type = DeviceType::ALL): PromotionList
	{
		$promoList = new PromotionList();

		if (!Loader::includeModule('ui'))
		{
			return $promoList;
		}

		foreach ($this->getConfig() as $config)
		{
			$tour = $this->getTour($config, $type);
			if (!$tour || !$tour->isAvailable())
			{
				continue;
			}

			$promo = new Promotion($tour->getId());
			$promoList->add($promo);
		}

		return $promoList;
	}

	private function getConfig(): array
	{
		$result = [];

		if (!Loader::includeModule('ui'))
		{
			return $result;
		}

		$result[] = [
			"ID" => 'im:group-chat-create:20062023:all',
			"USER_TYPE" => UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
			"END_DATE" => (new DateTime('01.11.2025', 'd.m.Y'))->getTimestamp()
		];

		$result[] = [
			"ID" => 'im:conference-create:24082023:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
			"END_DATE" => (new DateTime('01.11.2025', 'd.m.Y'))->getTimestamp()
		];

		$result[] = [
			"ID" => 'im:channel-create:04032024:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
			"END_DATE" => (new DateTime('01.11.2025', 'd.m.Y'))->getTimestamp()
		];

		$result[] = [
			"ID" => 'im:collab-create:12092024:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
			"LIFETIME" => self::ONE_MONTH * 2,
		];

		$result[] = [
			"ID" => 'im:add-users-to-copilot-chat:09042024:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
			"END_DATE" => (new DateTime('01.11.2025', 'd.m.Y'))->getTimestamp()
		];

		$result[] = [
			"ID" => 'im:change-role-copilot-chat:09042024:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
			"END_DATE" => (new DateTime('01.11.2025', 'd.m.Y'))->getTimestamp()
		];

		$result[] = [
			"ID" => 'im:collab-helpdesk-sidebar:30102024:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
		];

		$result[] = [
			"ID" => 'call:copilot-call-button:29102024:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
		];

		$result[] = [
			"ID" => 'call:copilot-notify-warning:21112024:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
		];

		$result[] = [
			"ID" => 'call:copilot-notify-promo:21112024:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
		];

		$result[] = [
			"ID" => 'call:callcontrol-notify-promo:07052025:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::ALL->value,
		];

		$result[] = [
			"ID" => 'im:download-several-files:22112024:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
		];

		$result[] = [
			"ID" => 'call:copilot-notify-result:24112024:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
		];

		$result[] = [
			"ID" => 'im:copilot-in-default-tab:11032025:all',
			"USER_TYPE" => UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::ALL->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
			"END_DATE" => (new DateTime('30.04.2026', 'd.m.Y'))->getTimestamp(),
		];

		$result[] = [
			"ID" => 'call:cloud-record-info-popup:09092025:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::ALL->value,
		];

		if (
			Features::isBitrixGptV2Available()
			&& ServiceLocator::getInstance()->get(AiAssistantService::class)->isBitrixGptAgentPromoAllowedByPortalAge()
		)
		{
			$result[] = [
				"ID" => 'im:bitrix-gpt-agent:20052026:all',
				"USER_TYPE" => UserType::ALL->value,
				"DEVICE_TYPE" => DeviceType::WEB->value,
				"LIFETIME" => self::ENDLESS_LIFETIME,
			];
		}

		$result[] = [
			"ID" => 'call:web-background-promo:13082026:all',
			"USER_TYPE" => UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
		];

		if (Locator::getMessenger()->getApplication()->isAirDesignEnabled())
		{
			$result[] = [
				"ID" => 'im:air-chat-empty-state:29042025:all',
				"USER_TYPE" => UserType::ALL->value,
				"DEVICE_TYPE" => DeviceType::WEB->value,
				"LIFETIME" => self::ENDLESS_LIFETIME,
			];
		}

		if (Features::get()->intranetInviteAvailable)
		{
			$result[] = [
				"ID" => 'im:recent-create-chat-invite-users:22052025:all',
				"USER_TYPE" => UserType::ALL->value,
				"DEVICE_TYPE" => DeviceType::WEB->value,
				"LIFETIME" => self::ENDLESS_LIFETIME,
			];
		}

		$result[] = [
			"ID" => 'im:chat-header-change-copilot-engine-and-role:20052025:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::ALL->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
			"END_DATE" => (new DateTime('30.04.2027', 'd.m.Y'))->getTimestamp(),
		];

		$result[] = [
			"ID" => 'im:chat-sidebar-change-copilot-engine-and-role:20052025:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::ALL->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
			"END_DATE" => (new DateTime('30.04.2027', 'd.m.Y'))->getTimestamp(),
		];

		$result[] = [
			"ID" => 'im:unread-recent-mode:10112025:all',
			"USER_TYPE" => UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::ALL->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
		];

		if (Features::get()->isTasksRecentListAvailable)
		{
			$result[] = [
				"ID" => 'immobile:tasks-recent-list:18112025:mobile',
				"USER_TYPE" => UserType::ALL->value,
				"DEVICE_TYPE" => DeviceType::MOBILE->value,
				"LIFETIME" => self::ENDLESS_LIFETIME,
			];
		}

		$result[] = [
			"ID" => 'im:create-collab-nested-chat-description:20032026:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
		];

		$result[] = [
			"ID" => 'im:collab-card-navigation:28042026:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
		];

		$result[] = [
			"ID" => 'im:collab-create-chat:28042026:all',
			"USER_TYPE" =>  UserType::ALL->value,
			"DEVICE_TYPE" => DeviceType::WEB->value,
			"LIFETIME" => self::ENDLESS_LIFETIME,
		];

		if (Features::isMountedTasksCardAvailable())
		{
			$result[] = [
				"ID" => 'im:task-chat-side-card:24062026:all',
				"USER_TYPE" => UserType::ALL->value,
				"DEVICE_TYPE" => DeviceType::WEB->value,
				"LIFETIME" => self::ENDLESS_LIFETIME,
			];
		}

		if (Features::isChatFolderAllAvailable() && $this->isFolderAllOnboardingPromoAllowedByPortalAge())
		{
			// One record per platform: the "seen" fact is stored per promo id, and the web and the app
			// are released independently, so viewing the onboarding on one keeps it waiting on the other.
			// Endless by intent: without LIFETIME the promo expires thirty days after its first check.
			$result[] = [
				"ID" => 'im:folder-all-onboarding:12082026:all',
				"USER_TYPE" => UserType::ALL->value,
				"DEVICE_TYPE" => DeviceType::WEB->value,
				"LIFETIME" => self::ENDLESS_LIFETIME,
			];

			$result[] = [
				"ID" => 'immobile:folder-all-onboarding:12082026:mobile',
				"USER_TYPE" => UserType::ALL->value,
				"DEVICE_TYPE" => DeviceType::MOBILE->value,
				"LIFETIME" => self::ENDLESS_LIFETIME,
			];
		}

		$result[] = [
			'ID' => 'im:main-reaction-onboarding:20072026:all',
			'USER_TYPE' => UserType::ALL->value,
			'DEVICE_TYPE' => DeviceType::WEB->value,
			'LIFETIME' => self::ENDLESS_LIFETIME,
			'START_DATE' => (new DateTime('20.07.2026', 'd.m.Y'))->getTimestamp(),
		];

		if (
			Features::isVoiceMessageRecordingAvailable()
			&& $this->isVoiceMessagePromoAllowedByPortalAge()
		)
		{
			$result[] = [
				'ID' => 'im:voice-message-recording:26082026:old',
				'USER_TYPE' => UserType::OLD->value,
				'USER_TIMESPAN' => self::ONE_MONTH,
				'DEVICE_TYPE' => DeviceType::WEB->value,
				'LIFETIME' => self::ENDLESS_LIFETIME,
			];
		}

		$settings = Configuration::getValue('im');
		if (isset($settings['promotion']) && is_array($settings['promotion']))
		{
			$result = array_merge($result, $settings['promotion']);
		}

		return $result;
	}

	private function isFolderAllOnboardingPromoAllowedByPortalAge(): bool
	{
		return PortalAge::isCreatedBeforeCutoffDateOrUnknown(
			'im',
			self::FOLDER_ALL_ONBOARDING_PROMO_CUTOFF_DATE_OPTION,
			self::FOLDER_ALL_ONBOARDING_PROMO_CUTOFF_DATE_DEFAULT,
		);
	}

	private function isVoiceMessagePromoAllowedByPortalAge(): bool
	{
		return PortalAge::isCreatedBeforeCutoffDate(
			'im',
			self::VOICE_MESSAGE_PROMO_CUTOFF_DATE_OPTION,
			self::VOICE_MESSAGE_PROMO_CUTOFF_DATE_DEFAULT
		);
	}

	private function getTour(array $config, DeviceType $type = DeviceType::ALL): bool|Tour|null
	{
		if (!Loader::includeModule('ui'))
		{
			return null;
		}

		$deviceType = (string)$config['DEVICE_TYPE'];
		if (!$type->isDeviceTypeAvailable($deviceType))
		{
			return false;
		}

		$tour = new Tour($config["ID"]);

		$params = [
			"USER_TYPE" => "setUserType",
			"USER_TIMESPAN" => "setUserTimeSpan",
			"LIFETIME" => "setLifetime",
			"START_DATE" => "setStartDate",
			"END_DATE" => "setEndDate",
		];

		foreach ($params as $param => $setter)
		{
			if (isset($config[$param]))
			{
				$tour->$setter($config[$param]);
			}
		}

		return $tour;
	}

	public function getTourById(string $id): ?Tour
	{
		foreach ($this->getConfig() as $config)
		{
			if ($config['ID'] === $id)
			{
				return $this->getTour($config);
			}
		}

		return null;
	}

	public function getPromotionType(): PromotionType
	{
		return PromotionType::UI;
	}

	public function isCurrentTypePromotion(string $promotionId): bool
	{
		return in_array($promotionId, $this->getActive()->toIdList(), true);
	}

	public function markAsViewed(Promotion $promotion): Result
	{
		$result = new Result();
		$promoId = $promotion->getId();

		$tour = $this->getTourById($promoId);
		if (!$tour || !$tour->isAvailable())
		{
			$result->addError(new Error('Promotion is not available'));

			return $result;
		}

		$userId = Common::getUserId();
		$tour->setViewDate($userId);

		if (Loader::includeModule('pull'))
		{
			Event::add($userId, [
				'module_id' => 'im',
				'command' => 'promotionRead',
				'params' => ['id' => $promoId],
				'extra' => Common::getPullExtra()
			]);
		}

		return $result;
	}
}
