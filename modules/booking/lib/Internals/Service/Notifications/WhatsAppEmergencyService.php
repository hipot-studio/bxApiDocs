<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications;

use Bitrix\Booking\Internals\Repository\OptionRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ORM\BookingRepository;
use Bitrix\Booking\Internals\Service\OptionDictionary;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Main\Application;
use Bitrix\Main\ModuleManager;

class WhatsAppEmergencyService
{
	private const REGIONS = ['ru'];

	public function __construct(
		private readonly BookingRepository $bookingRepository,
		private readonly OptionRepositoryInterface $optionRepository
	)
	{
	}

	public function shouldNotify(int $userId): bool
	{
		if (!ModuleManager::isModuleInstalled('bitrix24'))
		{
			return false;
		}

		$region = Application::getInstance()->getLicense()->getRegion() ?? 'en';
		if (!in_array($region, self::REGIONS, true))
		{
			return false;
		}

		$isWhatsAppEmergencyNotified = filter_var(
			$this->optionRepository->get(
				userId: $userId,
				option: OptionDictionary::WhatsAppEmergencyNotified,
				default: 'false',
			),
			FILTER_VALIDATE_BOOLEAN,
		);

		if ($isWhatsAppEmergencyNotified)
		{
			return false;
		}

		return (bool)$this->bookingRepository
			->getQuery(new BookingFilter(['CREATED_BY' => $userId]))
			->setSelect(['ID'])
			->setLimit(1)
			->fetch()
		;
	}
}
