<?php

namespace Bitrix\CrmMobile\Service;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Service\UserPermissions\Configuration;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Main\Loader;
use CCrmOwnerType;

Loader::requireModule('crm');

/**
 * The configuration capabilities of one mobile funnel screen, asked of whichever CRM the box carries.
 *
 * Where the CRM carries no granular facade every capability - the tunnels included - falls back to
 * `isAdminForEntity($entityTypeId)`: the former check of these surfaces cannot be derived from the
 * capability asked, so it is named here once and in the single form those surfaces used it.
 *
 * The scope of every capability but the tunnels carries no funnel. The tunnels are the exception:
 * their scope carries the funnel resolved on the server, because once the operations are registered
 * the capability reads the robots right of the funnel of the scope, and a scope without one asks
 * about funnel `0` ({@see Configuration::can()}).
 *
 * A lead has no funnel to manage, to create or to give access to - a condition of the surface rather
 * than of the right.
 */
final class ConfigurationPermissions
{
	/**
	 * The screen these capabilities belong to, and `null` in the screen itself. Every capability but
	 * the tunnels is decided by the screen, so a list of funnels asks it once instead of once per
	 * funnel.
	 */
	private ?self $screen = null;

	private ?bool $funnelsAnswer = null;

	private ?bool $stagesAnswer = null;

	private ?bool $tunnelsAnswer = null;

	private ?bool $categoryAccessAnswer = null;

	/**
	 * $configuration is `null` where the CRM of the box carries no granular facade.
	 */
	public function __construct(
		private readonly UserPermissions $userPermissions,
		private readonly int $entityTypeId,
		private readonly ?int $categoryId,
		private readonly ?Configuration $configuration,
	)
	{
	}

	public static function createForEntityType(int $entityTypeId, ?int $categoryId = null): self
	{
		$userPermissions = Container::getInstance()->getUserPermissions();

		return new self(
			$userPermissions,
			$entityTypeId,
			$categoryId,
			self::supportsGranularConfiguration() ? $userPermissions->configuration() : null,
		);
	}

	/**
	 * The same screen asked about one of its funnels: only the tunnels are decided in the funnel, and
	 * every other capability keeps the answer the screen already gave.
	 */
	public function forFunnel(?int $categoryId): self
	{
		$funnel = new self($this->userPermissions, $this->entityTypeId, $categoryId, $this->configuration);
		$funnel->screen = $this->screen ?? $this;

		return $funnel;
	}

	/**
	 * Whether the granular model decides the rights of this contour at all. A contour the model
	 * excludes from itself ({@see ConfigurationScope::isExcluded()}) and a CRM stating nothing about
	 * its contours both leave the former check of the surface as the only one there is.
	 */
	public function modelOwnsContour(): bool
	{
		if (!self::supportsConfigurationScope())
		{
			return false;
		}

		return !$this->contourScope()->isExcluded();
	}

	/**
	 * At least one operation of the screen is available to the user, so the screen opens at all.
	 */
	public function canOpenSettings(): bool
	{
		return $this->managesFunnels() || $this->canManageStages() || $this->canManageTunnels();
	}

	public function canCreateCategory(): bool
	{
		// one right of the contour serves both funnel buttons of the screen, as it does on the web.
		return $this->canManageFunnels();
	}

	public function canManageFunnels(): bool
	{
		return $this->managesFunnels() && !$this->isLead();
	}

	public function canManageStages(): bool
	{
		if ($this->screen !== null)
		{
			return $this->screen->canManageStages();
		}

		return $this->stagesAnswer ??= $this->askManageStages();
	}

	public function canManageTunnels(): bool
	{
		return $this->tunnelsAnswer ??= $this->askManageTunnels();
	}

	public function canManageCategoryAccess(): bool
	{
		if ($this->isLead())
		{
			return false;
		}

		if ($this->screen !== null)
		{
			return $this->screen->canManageCategoryAccess();
		}

		return $this->categoryAccessAnswer ??= $this->askManageCategoryAccess();
	}

	private function managesFunnels(): bool
	{
		if ($this->screen !== null)
		{
			return $this->screen->managesFunnels();
		}

		return $this->funnelsAnswer ??= $this->askManageFunnels();
	}

	private function askManageFunnels(): bool
	{
		if ($this->configuration === null)
		{
			return $this->formerCheck();
		}

		return $this->configuration->canManageFunnels(
			ConfigurationCallSite::MobileCategoryController,
			$this->screenScope(),
		);
	}

	private function askManageStages(): bool
	{
		if ($this->configuration === null)
		{
			return $this->formerCheck();
		}

		return $this->configuration->canManageStages(
			ConfigurationCallSite::MobileStageController,
			$this->screenScope(),
		);
	}

	private function askManageTunnels(): bool
	{
		if ($this->configuration === null)
		{
			return $this->formerCheck();
		}

		return $this->configuration->canManageTunnels(
			ConfigurationCallSite::MobileStageController,
			$this->contourScope(),
		);
	}

	private function askManageCategoryAccess(): bool
	{
		if ($this->configuration === null)
		{
			return $this->formerCheck();
		}

		return $this->configuration->canManageCategoryAccess(
			ConfigurationCallSite::MobileCategoryController,
			$this->screenScope(),
		);
	}

	private function formerCheck(): bool
	{
		return $this->userPermissions->isAdminForEntity($this->entityTypeId);
	}

	private function isLead(): bool
	{
		return $this->entityTypeId === CCrmOwnerType::Lead;
	}

	private function screenScope(): ConfigurationScope
	{
		return ConfigurationScope::ofEntityType($this->entityTypeId);
	}

	/**
	 * The contour of the funnel resolved on the server. The tunnels are decided in it, and the model
	 * states its own exclusions in it as well ({@see modelOwnsContour()}) - every other capability
	 * keeps the wider contour of {@see screenScope()}.
	 */
	private function contourScope(): ConfigurationScope
	{
		return ConfigurationScope::ofEntityType($this->entityTypeId, $this->categoryId);
	}

	/**
	 * Whether the CRM of the box carries the granular configuration contract as a whole: it may offer
	 * `configuration()` while the mobile call sites or the scope are still missing, and reaching for
	 * one of those would be a fatal error rather than a former check.
	 */
	private static function supportsGranularConfiguration(): bool
	{
		return self::supportsConfigurationScope()
			&& method_exists(UserPermissions::class, 'configuration')
			&& method_exists(Configuration::class, 'canManageFunnels')
			&& method_exists(Configuration::class, 'canManageStages')
			&& method_exists(Configuration::class, 'canManageTunnels')
			&& method_exists(Configuration::class, 'canManageCategoryAccess')
			&& defined(ConfigurationCallSite::class . '::MobileCategoryController')
			&& defined(ConfigurationCallSite::class . '::MobileStageController');
	}

	/**
	 * Whether the contours of the model can be resolved and asked about at all.
	 */
	private static function supportsConfigurationScope(): bool
	{
		return method_exists(ConfigurationScope::class, 'ofEntityType')
			&& method_exists(ConfigurationScope::class, 'isExcluded');
	}
}
