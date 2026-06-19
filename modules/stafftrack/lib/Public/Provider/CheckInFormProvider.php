<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Provider\OptionProvider;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayService;
use Bitrix\StaffTrack\Internal\Integration\Im\MessageService;
use Bitrix\StaffTrack\Internal\Integration\Pull;

class CheckInFormProvider
{
	public function load(int $userId, ?float $latitude = null, ?float $longitude = null): Result
	{
		$result = new Result();
		$data = [];

		Pull\PushSender::subscribeToTag(Pull\Tag::getUserTag($userId));

		if ($latitude !== null && $longitude !== null)
		{
			$geoData = $this->prepareGeoData($latitude, $longitude);
			if (!$geoData->isSuccess())
			{
				return $result->addErrors($geoData->getErrors());
			}

			$data = $geoData->getData();
		}

		$result->setData([
			...$data,
			'workDaySettings' => WorkDayService::getWorkTime($userId),
			'enabledBySettings' => Feature::isCheckInEnabledBySettings(),
			...$this->getDialogInfo($userId),
		]);

		return $result;
	}

	private function prepareGeoData(float $latitude, float $longitude): Result
	{
		$geoProvider = new GeoProvider($latitude, $longitude);

		$snapshotData = $this->prepareSnapshotData($geoProvider);
		if (!$snapshotData->isSuccess())
		{
			return $snapshotData;
		}

		$addressData = $this->prepareAddressData($geoProvider);
		if (!$addressData->isSuccess())
		{
			return $addressData;
		}

		$result = new Result();
		$result->setData([
			'address' => $addressData->getData(),
			'snapshot' => $snapshotData->getData(),
		]);

		return $result;
	}

	private function prepareSnapshotData(GeoProvider $geoProvider): Result
	{
		$result = new Result();

		$snapshot = $geoProvider->generateSnapshot();
		if (!$snapshot->isSuccess())
		{
			return $result->addErrors($snapshot->getErrors());
		}

		$snapshotUrl = $snapshot->getData()['snapshotUrl'];
		$signer = new Signer();

		$result->setData([
			'signedImageUrl' => $signer->sign($snapshotUrl),
			'imageUrl' => $snapshotUrl,
		]);

		return $result;
	}

	private function prepareAddressData(GeoProvider $geoProvider): Result
	{
		$result = new Result();

		$addressResult = $geoProvider->generateAddress();
		if (!$addressResult->isSuccess())
		{
			return $result->addErrors($addressResult->getErrors());
		}

		$addressData = $addressResult->getData();
		$signer = new Signer();

		$result->setData([
			'signedAddress' => $signer->sign($addressData['address']),
			'address' => $addressData['address'],
			'locationExternalId' => $addressData['externalId'],
			'locationSourceCode' => $addressData['sourceCode'],
		]);

		return $result;
	}

	private function getDialogInfo(int $userId): array
	{
		$dialogId = OptionProvider::getInstance()
			->getOption($userId, Option::LAST_SELECTED_DIALOG_ID)
			?->getValue()
		;

		return MessageService::getDialogInfo($dialogId, $userId);
	}
}
