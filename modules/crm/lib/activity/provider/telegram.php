<?php

namespace Bitrix\Crm\Activity\Provider;

use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use CCrmActivity;
use CCrmActivityType;

Loc::loadMessages(__FILE__);

class Telegram extends BaseMessage
{
	public const PROVIDER_TYPE_TELEGRAM = 'TELEGRAM';

	/**
	 * @inheritDoc
	 */
	protected static function getDefaultTypeId(): string
	{
		return self::PROVIDER_TYPE_TELEGRAM;
	}

	/**
	 * @inheritDoc
	 */
	protected static function getRenderViewComponentName(): string
	{
		return 'bitrix:crm.activity.sms';
	}

	/**
	 * @inheritDoc
	 */
	protected static function fetchEventParams(Event $event): array
	{
		return Sms::fetchEventParams($event);
	}

	protected static function getHandledInEventProviderTypeIds(): ?array
	{
		return [self::PROVIDER_TYPE_TELEGRAM];
	}

	/**
	 * @inheritDoc
	 */
	protected static function fetchActivityByMessageId(int $id): array
	{
		$activity = CCrmActivity::GetList([], [
			'TYPE_ID' => CCrmActivityType::Provider,
			'PROVIDER_ID' => static::getId(),
			'@PROVIDER_TYPE_ID' => [
				static::PROVIDER_TYPE_TELEGRAM,
				static::PROVIDER_TYPE_SALESCENTER_PAYMENT_SENT,
				static::PROVIDER_TYPE_SALESCENTER_DELIVERY,
			],
			'ASSOCIATED_ENTITY_ID' => $id,
			'CHECK_PERMISSIONS' => 'N',
		])?->Fetch();

		return is_array($activity) ? $activity : [];
	}

	/**
	 * @inheritDoc
	 */
	protected static function fetchOriginalMessageFields(int $messageId): array
	{
		return Sms::fetchOriginalMessageFields($messageId);
	}

	/**
	 * @inheritDoc
	 */
	protected static function syncActivitySettings(int $messageId, array $activity): void
	{
		Sms::syncActivitySettings($messageId, $activity);
	}

	public static function getId()
	{
		return 'CRM_TELEGRAM';
	}

	public static function getName()
	{
		return 'TELEGRAM';
	}

	/**
	 * @inheritdoc
	 */
	public static function syncBadges(int $activityId, array $activityFields, array $bindings): void
	{
		Sms::syncBadges($activityId, $activityFields, $bindings);
	}

	public static function getMessageStatusCode(int $statusId, Event $event): ?int
	{
		return Sms::getMessageStatusCode($statusId, $event);
	}
}
