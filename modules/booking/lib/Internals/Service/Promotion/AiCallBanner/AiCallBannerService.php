<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Promotion\AiCallBanner;

use Bitrix\Booking\Internals\Integration\Bizproc\AiCallMessageSender;
use Bitrix\Booking\Internals\Integration\Crm\CrmMessageSender;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\OptionRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Service\Notifications\AiCallMassSwitcher;
use Bitrix\Booking\Internals\Service\OptionDictionary;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Main\Web\Json;

class AiCallBannerService
{
	private const MAX_SHOWS = 4;
	private const SHOW_INTERVAL_SECONDS = 7 * 86400;
	private const MAX_SHOW_TIMESTAMP = 1830297599;   // 2027-12-31T23:59:59Z
	private const AUTO_SWITCH_ENABLED = false;

	public function __construct(
		private readonly OptionRepositoryInterface $optionRepository,
		private readonly AiCallMessageSender $aiCallMessageSender,
		private readonly CrmMessageSender $crmMessageSender,
		private readonly BookingRepositoryInterface $bookingRepository,
		private readonly ResourceRepositoryInterface $resourceRepository,
		private readonly AiCallMassSwitcher $aiCallMassSwitcher,
	)
	{
	}

	public function getMode(int $userId): AiCallBannerMode|null
	{
		$now = time();
		if ($now > self::MAX_SHOW_TIMESTAMP)
		{
			return null;
		}

		if ($this->aiCallMassSwitcher->isSwitched())
		{
			return null;
		}

		if (!$this->aiCallMessageSender->canUse())
		{
			return null;
		}

		if (!$this->userHasActivity($userId))
		{
			return null;
		}

		$state = $this->readState($userId);

		if ($state['count'] >= self::MAX_SHOWS)
		{
			if (!self::AUTO_SWITCH_ENABLED)
			{
				return null;
			}

			if (!$this->hasActiveBitrix24Resource())
			{
				return null;
			}

			$this->aiCallMassSwitcher->switchAllToAiCall();

			return AiCallBannerMode::AutoSwitched;
		}

		if (
			$state['lastShownAt'] > 0
			&& ($now - $state['lastShownAt']) < self::SHOW_INTERVAL_SECONDS
		)
		{
			return null;
		}

		if (!$this->hasActiveBitrix24Resource())
		{
			return null;
		}

		return AiCallBannerMode::Invitation;
	}

	public function registerShown(int $userId): void
	{
		$state = $this->readState($userId);

		$this->writeState($userId, [
			'count' => $state['count'] + 1,
			'lastShownAt' => time(),
		]);
	}

	/**
	 * @return array{count: int, lastShownAt: int}
	 */
	private function readState(int $userId): array
	{
		$raw = $this->optionRepository->get(
			userId: $userId,
			option: OptionDictionary::AiCallBanner,
			default: null,
		);

		if ($raw === null || $raw === '')
		{
			return ['count' => 0, 'lastShownAt' => 0];
		}

		try
		{
			$decoded = Json::decode($raw);
		}
		catch (\Throwable)
		{
			return ['count' => 0, 'lastShownAt' => 0];
		}

		return [
			'count' => isset($decoded['count']) ? (int)$decoded['count'] : 0,
			'lastShownAt' => isset($decoded['lastShownAt']) ? (int)$decoded['lastShownAt'] : 0,
		];
	}

	/**
	 * @param array{count: int, lastShownAt: int} $state
	 */
	private function writeState(int $userId, array $state): void
	{
		$this->optionRepository->set(
			userId: $userId,
			option: OptionDictionary::AiCallBanner,
			value: Json::encode($state),
		);
	}

	private function userHasActivity(int $userId): bool
	{
		$bookingRow = $this->bookingRepository
			->getQuery(new BookingFilter([
				'CREATED_BY' => $userId,
				'INCLUDE_DELETED' => true,
			]))
			->setSelect(['ID'])
			->setLimit(1)
			->fetch()
		;

		if ($bookingRow !== false)
		{
			return true;
		}

		$resourceRow = $this->resourceRepository
			->getQuery(new ResourceFilter([
				'CREATED_BY' => $userId,
				'INCLUDE_DELETED' => true,
			]))
			->setSelect(['ID'])
			->setLimit(1)
			->fetch()
		;

		return $resourceRow !== false;
	}

	private function hasActiveBitrix24Resource(): bool
	{
		$sourceSenderCode = $this->crmMessageSender->getCode();
		if ($sourceSenderCode === '')
		{
			return false;
		}

		$row = $this->resourceRepository
			->getQuery(new ResourceFilter(['SENDER_CODE' => $sourceSenderCode]))
			->setSelect(['ID'])
			->setLimit(1)
			->fetch()
		;

		return $row !== false;
	}
}
