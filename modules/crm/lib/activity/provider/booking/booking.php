<?php

namespace Bitrix\Crm\Activity\Provider\Booking;

use Bitrix\Crm\Activity;
use Bitrix\Crm\Timeline;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Booking extends Activity\Provider\Base
{
	private const PROVIDER_TYPE_DEFAULT = 'BOOKING';

	public static function getId(): string
	{
		return 'CRM_BOOKING';
	}

	public static function getTypeId(array $activity): string
	{
		return self::PROVIDER_TYPE_DEFAULT;
	}

	public static function getTypes(): array
	{
		return [
			[
				'NAME' => Loc::getMessage('CRM_ACTIVITY_PROVIDER_BOOKING_TYPE_DEFAULT_NAME'),
				'PROVIDER_ID' => self::getId(),
				'PROVIDER_TYPE_ID' => self::PROVIDER_TYPE_DEFAULT,
			],
		];
	}

	public static function getName(): string
	{
		return Loc::getMessage('CRM_ACTIVITY_PROVIDER_BOOKING_NAME');
	}

	public static function getTypeName($providerTypeId = null, $direction = \CCrmActivityDirection::Undefined): string
	{
		return Loc::getMessage('CRM_ACTIVITY_PROVIDER_BOOKING_TYPE_DEFAULT_NAME');
	}

	public static function getFieldsForEdit(array $activity): array
	{
		return [];
	}

	public static function onBookingAdded(array $booking): int|null
	{
		if (empty($booking['id']))
		{
			return null;
		}

		$typeId = self::PROVIDER_TYPE_DEFAULT;

		$bindings = BookingCommon::makeBindings($booking);

		if (empty($bindings))
		{
			return null;
		}

		$activityId = BookingCommon::updateActivity(
			providerId: self::getId(),
			typeId: $typeId,
			entity: $booking,
			bindings: $bindings,
		);
		if ($activityId)
		{
			return $activityId;
		}

		$activityId = BookingCommon::createActivity(
			providerId: self::getId(),
			typeId: $typeId,
			entity: $booking,
			bindings: $bindings,
			subject: BookingCommon::getActivitySubject(
				self::getTypeName($typeId),
				$booking['name'] ?: Loc::getMessage('CRM_ACTIVITY_PROVIDER_BOOKING_NAME'),
			),
		);

		if (!$activityId)
		{
			return null;
		}

		(new TimeLine\Booking\Controller())->onBookingCreated($bindings, $booking);

		return $activityId;
	}

	public static function onBookingUpdated(array $booking): int|null
	{
		return self::onBookingAdded($booking);
	}

	public static function onBookingDeleted(int $bookingId): void
	{
		$activitiesList = \CCrmActivity::getList(
			[],
			[
				'=PROVIDER_ID' => self::getId(),
				'=PROVIDER_TYPE_ID' => self::PROVIDER_TYPE_DEFAULT,
				'=ASSOCIATED_ENTITY_ID' => $bookingId,
				'=COMPLETED' => 'N',
				'CHECK_PERMISSIONS' => 'N',
			]
		);
		while ($activity = $activitiesList->fetch())
		{
			$deleted = \CCrmActivity::Delete($activity['ID'], false);

			if ($deleted)
			{
				BookingCommon::sendPullEventOnDelete($activity);
			}
		}
	}
}
