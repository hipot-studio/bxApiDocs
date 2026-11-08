<?php

namespace Bitrix\Crm\Controller;

use Bitrix\Crm\Integration\Zoom\Conference;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Crm\Integration\Zoom\Activity;
use Bitrix\Crm\Service\Container;

class ZoomUser extends Controller
{
	private const LEGACY_JOIN_GRACE_PERIOD_SECONDS = 7 * 86400;

	protected function processBeforeAction(\Bitrix\Main\Engine\Action $action)
	{
		Loader::includeModule('crm');

		return parent::processBeforeAction($action);
	}

	public function configureActions(): array
	{
		return [
			'registerJoinMeeting' => [
				'-prefilters' => [
					Csrf::class,
					Authentication::class
				],
			]
		];
	}

	public function registerJoinMeetingAction(int $conferenceId, $linkCode = null, $joinToken = null)
	{
		if (!Loader::includeModule('socialservices'))
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		if (!method_exists(\Bitrix\SocialServices\Integration\Zoom\Conference::class, 'getJoinInfo'))
		{
			return $this->registerJoinMeetingWithoutToken($conferenceId);
		}

		$joinInfo = \Bitrix\SocialServices\Integration\Zoom\Conference::getJoinInfo(
			$conferenceId,
			is_string($linkCode) ? $linkCode : '',
			is_string($joinToken) ? $joinToken : '',
		);
		if (!$joinInfo->isSuccess())
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		if (!\CCrmSecurityHelper::IsAuthorized())
		{
			Conference::updateJoinedStatus($conferenceId, (int)$joinInfo->getData()['ID']);
		}

		return new Response\Redirect($joinInfo->getData()['CONFERENCE_URL'], true);
	}

	private function registerJoinMeetingWithoutToken(int $conferenceId)
	{
		$conferenceInfo = \Bitrix\SocialServices\Integration\Zoom\Conference::getInfo($conferenceId);
		if (!$conferenceInfo->isSuccess())
		{
			$this->addErrors($conferenceInfo->getErrors());
			return null;
		}

		$confData = $conferenceInfo->getData();
		if (!self::isLegacyConferenceAvailable($confData))
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		if (!\CCrmSecurityHelper::IsAuthorized())
		{
			Conference::updateJoinedStatus($conferenceId);
		}

		return new Response\Redirect($confData['CONFERENCE_URL'], true);
	}

	private static function isLegacyConferenceAvailable(array $conference): bool
	{
		$path = parse_url((string)($conference['SHORT_LINK'] ?? ''), PHP_URL_PATH);
		if (
			!is_string($path)
			|| preg_match('#^/~[A-Za-z0-9]{5}$#D', $path) !== 1
			|| ($conference['CONFERENCE_ENDED'] ?? null) !== null
		)
		{
			return false;
		}

		$startedAt = $conference['CONFERENCE_STARTED'] ?? $conference['CONFERENCE_CREATED'] ?? null;
		if (!$startedAt instanceof DateTime)
		{
			return false;
		}

		$joinDeadline = $startedAt->getTimestamp()
			+ (int)($conference['DURATION'] ?? 0) * 60
			+ self::LEGACY_JOIN_GRACE_PERIOD_SECONDS
		;

		return time() <= $joinDeadline;
	}

	public function createConferenceAction($conferenceParams, $entityId, $entityType)
	{
		if (!Container::getInstance()->getUserPermissions()->item()->canUpdate(\CCrmOwnerType::ResolveID((string)$entityType), (int)$entityId))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}
		$createResult = Conference::createZoom(\CCrmSecurityHelper::GetCurrentUserID(), $conferenceParams);
		if (!$createResult->isSuccess())
		{
			$this->addErrors($createResult->getErrors());
			return null;
		}
		$conferenceData = $createResult->getData();

		$activity = new Activity($entityId, $entityType);
		$resultAddActivity = $activity->addZoom([
			'id' => $conferenceData['id'],
			'bitrix_internal_id' => $conferenceData['bitrix_internal_id'],
			'start_time' => $conferenceData['start_time'],
			'duration' => $conferenceData['duration'],
		]);
		if (!$resultAddActivity->isSuccess())
		{
			$this->addErrors($resultAddActivity->getErrors());
			return null;
		}
		$activityResult = $resultAddActivity->getData();
		$bindResult = \Bitrix\SocialServices\Integration\Zoom\Conference::bindActivity(
			['id' => $conferenceData['id']],
			$activityResult['ACTIVITY_ID']
		);
		if (!$bindResult->isSuccess())
		{
			$this->addErrors($bindResult->getErrors());
			return null;
		}

		return [];
	}
}
