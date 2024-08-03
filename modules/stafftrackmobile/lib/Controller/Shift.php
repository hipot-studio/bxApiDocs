<?php

namespace Bitrix\StaffTrackMobile\Controller;

use Bitrix\Disk\Driver;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\SystemException;
use Bitrix\Main\Text\Emoji;
use Bitrix\StaffTrack\Dictionary\Mute;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Feature;
use Bitrix\Stafftrack\Integration\HumanResources\Structure;
use Bitrix\StaffTrack\Integration\Im\MessageService;
use Bitrix\StaffTrack\Integration\Location\GeoService;
use Bitrix\StaffTrack\Internals\Exception\IntranetUserException;
use Bitrix\StaffTrack\Internals\Exception\UserNotFoundException;
use Bitrix\StaffTrack\Item\User;
use Bitrix\StaffTrack\Model\Counter;
use Bitrix\StaffTrack\Provider\CounterProvider;
use Bitrix\StaffTrack\Provider\OptionProvider;
use Bitrix\StaffTrack\Provider\ShiftProvider;
use Bitrix\StaffTrack\Provider\UserProvider;
use Bitrix\StaffTrack\Service\CounterService;
use Bitrix\StaffTrack\Service\OptionService;
use Bitrix\StaffTrack\Trait\CurrentUserTrait;
use Bitrix\Stafftrack\Integration\Pull;

class Shift extends Controller
{
	use CurrentUserTrait;

	/**
	 * @return array[]
	 */
	public function configureActions(): array
	{
		return [
			'loadMain' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'list' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\ObjectException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function loadMainAction(string $date): array
	{
		$result = [];

		try
		{
			$userId = $this->getCurrentUserId();
			$provider = ShiftProvider::getInstance($userId);

			$user = UserProvider::getInstance()->getUser($userId);

			$result = [
				'currentShift' => $provider->findByDate($date),
				'enabledBySettings' => Feature::isCheckInEnabledBySettings(),
				'isGeoByDefaultZone' => $this->isGeoByDefaultZone(),
				'dialogInfo' => $this->getDialogInfo($userId),
				'options' => $this->getOptions($userId),
				'userInfo' => $user?->toArray(),
				'diskFolderId' => $this->getDiskFolderId($userId),
				'counter' => $this->getCounter($userId),
				'departmentHeadId' => $this->getDepartmentHeadId($user),
			];

			Pull\PushService::subscribeToTag(Pull\Tag::getUserTag($userId));
		}
		catch (IntranetUserException|UserNotFoundException $exception)
		{
			$this->addError(Error::createFromThrowable($exception));
		}

		return $result;
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getOptions(int $userId): array
	{
		$optionProvider = OptionProvider::getInstance();
		$sendMessageOption = $optionProvider->getOption($userId, Option::SEND_MESSAGE)?->getValue();
		$sendGeoOption = $optionProvider->getOption($userId, Option::SEND_GEO)?->getValue();

		return [
			'defaultMessage' => Emoji::decode($optionProvider->getOption($userId, Option::DEFAULT_MESSAGE)?->getValue()),
			'defaultLocation' => Emoji::decode($optionProvider->getOption($userId, Option::DEFAULT_LOCATION)?->getValue()),
			'defaultCustomLocation' => Emoji::decode($optionProvider->getOption($userId, Option::DEFAULT_CUSTOM_LOCATION)?->getValue()),
			'isFirstHelpViewed' => $optionProvider->getOption($userId, Option::IS_FIRST_HELP_VIEWED)?->getValue() === 'Y',
			'sendGeo' => !$sendGeoOption || $sendGeoOption === 'Y',
			'sendMessage' => !$sendMessageOption || $sendMessageOption === 'Y',
			'selectedDepartmentId' => $optionProvider->getOption($userId, Option::SELECTED_DEPARTMENT_ID)?->getValue(),
		];
	}

	/**
	 * @param int $userId
	 * @return array|null[]
	 * @throws SystemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 */
	private function getDialogInfo(int $userId): array
	{
		$optionProvider = OptionProvider::getInstance();
		$dialogId = $optionProvider->getOption($userId, Option::LAST_SELECTED_DIALOG_ID)?->getValue();

		return (new MessageService($userId))->getDialogInfo($dialogId);
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getDiskFolderId(int $userId): ?int
	{
		if (!Loader::includeModule('disk'))
		{
			return null;
		}

		return Driver::getInstance()->getStorageByUserId($userId)?->getFolderForUploadedFiles()?->getId();
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getCounter(int $userId): ?Counter
	{
		return CounterProvider::getInstance()->get($userId);
	}

	/**
	 * @return bool
	 */
	private function isGeoByDefaultZone(): bool
	{
		$portalZone = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion() ?? 'en';

		return in_array($portalZone, ['ru', 'by', 'kz', 'br', 'in'], true);
	}

	protected function getDepartmentHeadId(?User $user): int
	{
		$departments = $user?->departments?->getValues();
		if (empty($departments))
		{
			return 0;
		}

		$department = $departments[0];

		return Structure::getInstance()->getDepartmentHeadId($department->id);
	}

	/**
	 * @throws \Bitrix\Main\SystemException
	 */
	public function listAction(
		array $filter = [],
		array $select = [],
		array $order = [],
		int $limit = 0
	): mixed
	{
		return $this->forward(
			\Bitrix\StaffTrack\Controller\Shift::class,
			'list',
			[
				'filter' => $filter,
				'select' => $select,
				'order' => $order,
				'limit' => $limit,
			],
		);
	}

	/**
	 * @throws SystemException
	 */
	public function addAction(array $fields): mixed
	{
		return $this->forward(
			\Bitrix\StaffTrack\Controller\Shift::class,
			'add',
			[
				'fields' => $fields,
			],
		);
	}

	/**
	 * @throws \Bitrix\Main\SystemException
	 */
	public function updateAction(int $id, array $fields): mixed
	{
		return $this->forward(
			\Bitrix\StaffTrack\Controller\Shift::class,
			'update',
			[
				'id' => $id,
				'fields' => $fields,
			],
		);
	}

	/**
	 * @throws SystemException
	 */
	public function deleteAction(int $id): mixed
	{
		return $this->forward(
			\Bitrix\StaffTrack\Controller\Shift::class,
			'delete',
			[
				'id' => $id,
			],
		);
	}

	/**
	 * @param float $latitude
	 * @param float $longitude
	 * @return array
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException|\Bitrix\Main\LoaderException
	 */
	public function getGeoInfoAction(float $latitude, float $longitude): array
	{
		$result = [];

		$geoService = new GeoService($latitude, $longitude);

		$staticMapResult = $geoService->generateStaticMap();
		if (!$staticMapResult->isSuccess())
		{
			$this->addErrors($staticMapResult->getErrors());

			return $result;
		}

		$addressResult = $geoService->generateAddress();
		if (!$addressResult->isSuccess())
		{
			$this->addErrors($addressResult->getErrors());

			return $result;
		}

		$geoImageUrl = $staticMapResult->getData()['geoImageUrl'];
		$addressString = $addressResult->getData()['addressString'];

		return [
			'signedGeoImageUrl' => (new Signer())->sign($geoImageUrl),
			'signedAddressString' => (new Signer())->sign($addressString),
			'geoImageUrl' => $geoImageUrl,
			'addressString' => $addressString,
		];
	}

	/**
	 * @return array
	 */
	public function handleFirstHelpViewAction(): array
	{
		$result = [];

		$userId = CurrentUser::get()?->getId();
		if ($userId === null)
		{
			$this->addError(new Error('User not found'));

			return $result;
		}

		OptionService::getInstance()->save($userId, Option::IS_FIRST_HELP_VIEWED, 'Y');

		return $result;
	}

	/**
	 * @param int $muteStatus
	 * @return array
	 */
	public function muteCounterAction(int $muteStatus): array
	{
		$result = [];

		$userId = CurrentUser::get()?->getId();
		if ($userId === null)
		{
			$this->addError(new Error('User not found'));

			return $result;
		}

		$muteEnum = Mute::tryFrom($muteStatus) ?? Mute::DISABLED;
		CounterService::getInstance()->save($userId, $muteEnum);

		return $result;
	}
}
