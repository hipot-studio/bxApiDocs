<?php

namespace Bitrix\Im\V2\Entity\User;

use Bitrix\Im\Bot;
use Bitrix\Im\Color;
use Bitrix\Im\Model\StatusTable;
use Bitrix\Im\Model\UserTable;
use Bitrix\Im\V2\Entity\User\Cache\UserCacheRegistry;
use Bitrix\Im\V2\Entity\User\Data\BotData;
use Bitrix\Im\V2\Integration\Extranet\CollaberService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use CVoxImplantPhone;

class UserFactory
{
	private const COMMON_SELECT_FIELD = [
		'ID',
		'LAST_NAME',
		'NAME',
		'EMAIL',
		'LOGIN',
		'PERSONAL_PHOTO',
		'SECOND_NAME',
		'PERSONAL_BIRTHDAY',
		'WORK_POSITION',
		'PERSONAL_GENDER',
		'EXTERNAL_AUTH_ID',
		'PERSONAL_WWW',
		'ACTIVE',
		'LANGUAGE_ID',
		'WORK_PHONE',
		'TIME_ZONE',
		'PERSONAL_MOBILE',
		'PERSONAL_PHONE',
		'COLOR' => 'ST.COLOR',
		'STATUS' => 'ST.STATUS',
	];

	protected static ?self $instance = null;

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		if (isset(self::$instance))
		{
			return self::$instance;
		}

		self::$instance = new static();

		return self::$instance;
	}

	public function getUserById(int $id): User
	{
		$result = ServiceLocator::getInstance()
			->get(UserCacheRegistry::class)
			?->getUserDataManager()
			->getOrSet(
				entityId: $id,
				dataProvider: fn() => $this->prepareUserData($this->getUserFromDb($id)),
				tags: ["USER_NAME_{$id}"],
			)
		;

		$user = $result->getResult();

		return $user ?? new NullUser();
	}

	public function initUser(array $userData): User
	{
		if ($userData['IS_BOT'])
		{
			return UserBot::initByArray($userData);
		}
		if (CollaberService::getInstance()->isCollaber((int)$userData['ID']))
		{
			return UserCollaber::initByArray($userData);
		}
		if ($userData['IS_EXTRANET'])
		{
			return UserExtranet::initByArray($userData);
		}
		if ($this->isExternal($userData))
		{
			return UserExternal::initByArray($userData);
		}

		return User::initByArray($userData);
	}

	public function prepareUserData(?array $userData): ?array
	{
		if ($userData === null)
		{
			return null;
		}

		$avatar = \CIMChat::GetAvatarImage($userData['PERSONAL_PHOTO']) ?: '';

		$preparedUserData = $userData;
		$preparedUserData['COLOR'] = $this->getColor($userData);
		$preparedUserData['STATUS'] = $userData['STATUS'] ?? null;
		$preparedUserData['NAME'] = \Bitrix\Im\User::formatFullNameFromDatabase($userData);
		$preparedUserData['FIRST_NAME'] = \Bitrix\Im\User::formatNameFromDatabase($userData);
		$preparedUserData['BIRTHDAY'] =
			$userData['PERSONAL_BIRTHDAY'] instanceof \Bitrix\Main\Type\Date
				? $userData['PERSONAL_BIRTHDAY']->format('d-m')
				: false
		;
		$preparedUserData['AVATAR'] = $avatar !== '/bitrix/js/im/images/blank.gif' ? $avatar : '';
		$preparedUserData['AVATAR_HR'] = $avatar;
		$preparedUserData['AVATAR_ID'] = (int)$userData['PERSONAL_PHOTO'];
		$preparedUserData['IS_EXTRANET'] = $this->isExtranet($userData);
		$preparedUserData['IS_NETWORK'] = $this->isNetwork($userData);
		$preparedUserData['IS_BOT'] = $this->isBot($userData);
		$preparedUserData['IS_CONNECTOR'] = $this->isConnector($userData);
		$preparedUserData['LANGUAGE_ID'] = $userData['LANGUAGE_ID'] ?? null;

		if (Loader::includeModule('voximplant'))
		{
			$preparedUserData['WORK_PHONE'] = CVoxImplantPhone::Normalize($userData['WORK_PHONE']) ?: null;
			$preparedUserData['PERSONAL_MOBILE'] = CVoxImplantPhone::Normalize($userData['PERSONAL_MOBILE']) ?: null;
			$preparedUserData['PERSONAL_PHONE'] = CVoxImplantPhone::Normalize($userData['PERSONAL_PHONE']) ?: null;
		}

		if (Loader::includeModule('intranet'))
		{
			$innerPhone = preg_replace("/[^0-9\#\*]/i", "", $userData['UF_PHONE_INNER'] ?? '');
			if ($innerPhone)
			{
				$preparedUserData['INNER_PHONE'] = $innerPhone;
			}
		}

		return $preparedUserData;
	}

	protected function getUserFromDb(int $id): ?array
	{
		$query = UserTable::query()
			->setSelect(self::COMMON_SELECT_FIELD)
			->setLimit(1)
			->where('ID', $id)
			->registerRuntimeField(
				'ST',
				new Reference(
					'ST',
					StatusTable::class,
					Join::on('this.ID', 'ref.USER_ID'),
					['join_type' => Join::TYPE_LEFT]
				)
			)
		;

		if (Loader::includeModule('intranet'))
		{
			$query
				->addSelect('UF_DEPARTMENT')
				->addSelect('UF_PHONE_INNER')
				->addSelect('UF_ZOOM')
				->addSelect('UF_SKYPE')
				->addSelect('UF_SKYPE_LINK')
			;
		}

		if (Loader::includeModule('voximplant'))
		{
			$query->addSelect('UF_VI_PHONE');
		}

		return $query->fetch() ?: null;
	}

	protected function isExtranet(array $params): bool
	{
		return \CIMContactList::IsExtranet($params);
	}

	protected function isNetwork(array $params): bool
	{
		$isNetworkUser = $params['EXTERNAL_AUTH_ID'] === \CIMContactList::NETWORK_AUTH_ID;
		$isNetworkBot = false;
		if ($params['EXTERNAL_AUTH_ID'] === Bot::EXTERNAL_AUTH_ID)
		{
			$isNetworkBot = BotData::getInstance((int)$params['ID'])->isNetworkBot();
		}

		return $isNetworkUser || $isNetworkBot;
	}

	protected function isBot(array $params): bool
	{
		return $params['EXTERNAL_AUTH_ID'] === Bot::EXTERNAL_AUTH_ID;
	}

	protected function isConnector(array $params): bool
	{
		return $params['EXTERNAL_AUTH_ID'] === 'imconnector';
	}

	protected function getColor(array $userData): string
	{
		return $userData['COLOR']
			? Color::getColor($userData['COLOR'])
			: $this->getColorByUserIdAndGender((int)$userData['ID'], $userData['PERSONAL_GENDER'] === 'M'? 'M': 'F');
	}

	protected function getColorByUserIdAndGender(int $id, string $gender): string
	{
		$code = Color::getCodeByNumber($id);
		if ($gender === 'M')
		{
			$replaceColor = Color::getReplaceColors();
			if (isset($replaceColor[$code]))
			{
				$code = $replaceColor[$code];
			}
		}

		return Color::getColor($code);
	}

	protected function isExternal(array $params): bool
	{
		return in_array($params['EXTERNAL_AUTH_ID'], UserTable::filterExternalUserTypes(['bot']), true);
	}

	public function clearCache(int $id): void
	{
		ServiceLocator::getInstance()
			->get(UserCacheRegistry::class)
			?->getUserDataManager()
			->clear(entityId: $id)
		;
	}
}
