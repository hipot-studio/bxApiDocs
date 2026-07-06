<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Application;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Rest\Internal\Entity\Application\AppLicenseState;
use Bitrix\Rest\Internal\Entity\Application\AppOrigin;
use Bitrix\Rest\Internal\Entity\Application\AppPricingModel;
use Bitrix\Rest\Internal\Model\AppStatus;

final class AppFilter
{
	private ?bool $active = null;
	private ?bool $installed = null;
	private ?AppOrigin $origin = null;
	private ?AppPricingModel $pricingModel = null;
	private ?AppLicenseState $licenseState = null;
	private ?bool $isInFreeWhitelist = null;
	private ?int $ownerUserId = null;
	private ?string $code = null;
	private ?array $attributes = null;
	private ?ConditionTree $where = null;

	public function active(?bool $active = true): self
	{
		$this->active = $active;

		return $this;
	}

	public function installed(?bool $installed = true): self
	{
		$this->installed = $installed;

		return $this;
	}

	public function origin(?AppOrigin $origin): self
	{
		$this->origin = $origin;

		return $this;
	}

	public function pricingModel(?AppPricingModel $pricingModel): self
	{
		$this->pricingModel = $pricingModel;

		return $this;
	}

	public function licenseState(?AppLicenseState $licenseState): self
	{
		$this->licenseState = $licenseState;

		return $this;
	}

	public function isInFreeWhitelist(?bool $isInFreeWhitelist = true): self
	{
		$this->isInFreeWhitelist = $isInFreeWhitelist;

		return $this;
	}

	public function ownerUserId(?int $ownerUserId): self
	{
		$this->ownerUserId = $ownerUserId;

		return $this;
	}

	public function code(?string $code): self
	{
		$this->code = $code;

		return $this;
	}

	public function attributes(?array $attributes): self
	{
		$this->attributes = $attributes;

		return $this;
	}

	public function where(?ConditionTree $where): self
	{
		$this->where = $where;

		return $this;
	}

	public function getActive(): ?bool
	{
		return $this->active;
	}

	public function getInstalled(): ?bool
	{
		return $this->installed;
	}

	public function getOrigin(): ?AppOrigin
	{
		return $this->origin;
	}

	public function getPricingModel(): ?AppPricingModel
	{
		return $this->pricingModel;
	}

	public function getLicenseState(): ?AppLicenseState
	{
		return $this->licenseState;
	}

	public function getIsInFreeWhitelist(): ?bool
	{
		return $this->isInFreeWhitelist;
	}

	public function getOwnerUserId(): ?int
	{
		return $this->ownerUserId;
	}

	public function getCode(): ?string
	{
		return $this->code;
	}

	public function getAttributes(): ?array
	{
		return $this->attributes;
	}

	public function getWhere(): ?ConditionTree
	{
		return $this->where;
	}

	/**
	 * @return AppStatus[]|null
	 */
	public function resolveStatuses(): ?array
	{
		if ($this->origin === null && $this->pricingModel === null && $this->licenseState === null)
		{
			return null;
		}

		$statuses = AppStatus::cases();

		if ($this->origin !== null)
		{
			$statuses = array_filter(
				$statuses,
				fn(AppStatus $s) => $s->getOrigin() === $this->origin,
			);
		}

		if ($this->pricingModel !== null)
		{
			$statuses = array_filter(
				$statuses,
				fn(AppStatus $s) => $s->getPricingModel() === $this->pricingModel,
			);
		}

		if ($this->licenseState !== null)
		{
			$statuses = array_filter(
				$statuses,
				fn(AppStatus $s) => $s->getLicenseState() === $this->licenseState,
			);
		}

		return array_values($statuses);
	}
}
