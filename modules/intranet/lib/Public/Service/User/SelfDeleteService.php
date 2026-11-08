<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Service\User;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Bitrix24\License\LicenseService;
use Bitrix\Intranet\Internal\Service\User\SelfDeleteScenarioResolver;
use Bitrix\Intranet\Internal\Service\User\SelfDeleteScenarioResolverInterface;
use Bitrix\Intranet\Internal\Service\User\SelfDeleteUserPolicy;
use Bitrix\Intranet\Public\Type\User\SelfDeleteScenario;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Main\Application;

final class SelfDeleteService
{
	public const TRANSFER_RIGHTS_HELPDESK_CODE = 26764700;
	public const PORTAL_DELETE_URL = '/settings/configs/?page=configuration&option=settings-configuration-section-delete-portal';

	public function __construct(
		private readonly ?SelfDeleteScenarioResolverInterface $scenarioResolver = null,
		private readonly ?LicenseService $licenseService = null,
		private readonly ?SelfDeleteUserPolicy $userPolicy = null,
	)
	{
	}

	public static function getSupportEmail(): string
	{
		return self::getSupportEmailByZone(Application::getInstance()->getLicense()->getRegion());
	}

	public function resolveScenarioByUserId(int $userId): SelfDeleteScenario
	{
		return $this->getScenarioResolver()->resolveByUserId($userId);
	}

	public function isAvailable(): bool
	{
		return $this->getLicenseService()->isCloud();
	}

	public function getCurrentUserPopupData(): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		$scenario = $this->resolveScenarioByUserId($currentUserId);

		return [
			'scenario' => $scenario->value,
			'adminIds' => $scenario === SelfDeleteScenario::NotLastAdmin
				? $this->getRequestChatAdminIdsByUserId($currentUserId)
				: [],
			'supportEmail' => self::getSupportEmail(),
			'transferRightsHelpdeskCode' => self::TRANSFER_RIGHTS_HELPDESK_CODE,
			'portalDeleteUrl' => self::PORTAL_DELETE_URL,
		];
	}

	private static function getSupportEmailByZone(?string $zone): string
	{
		return match ($zone)
		{
			'ru' => 'privacy@1c-bitrix.ru',
			'by' => 'in@1c-bitrix.by',
			'kz' => 'privacy@1c-bitrix.kz',
			'eu', 'de', 'fr', 'it', 'pl', 'uk' => 'privacy@bitrix24.eu',
			'uz' => 'privacy@1c-bitrix.uz',
			'la' => 'privacy@bitrix24.com',
			default => 'privacy@bitrix24.com',
		};
	}

	private function getRequestChatAdminIdsByUserId(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$adminIds = ServiceContainer::getInstance()->getUserService()->getAdminUserIds();

		if (empty($adminIds))
		{
			return [];
		}

		$adminUsers = ServiceContainer::getInstance()
			->userRepository()
			->findUsersByIds($adminIds)
		;
		$adminUsers = $adminUsers->filter(
			static fn(User $user): bool => $user->getId() !== $userId,
		);

		return $this->getUserPolicy()->filterActiveLocalAdmins($adminUsers)->getIds();
	}

	private function getScenarioResolver(): SelfDeleteScenarioResolverInterface
	{
		if ($this->scenarioResolver)
		{
			return $this->scenarioResolver;
		}

		return new SelfDeleteScenarioResolver();
	}

	private function getLicenseService(): LicenseService
	{
		return $this->licenseService ?? new LicenseService();
	}

	private function getUserPolicy(): SelfDeleteUserPolicy
	{
		return $this->userPolicy ?? new SelfDeleteUserPolicy();
	}
}
