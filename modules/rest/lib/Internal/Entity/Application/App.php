<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main;
use Bitrix\Rest\Public\Contract\Application\RestApplicationInterface;

class App implements EntityInterface, RestApplicationInterface
{
	private ?int $id = null;

	private ?AppAttributeCollection $attributes = null;

	private ?AppAttributeCollection $externalAttributes = null;

	private ?AppLangCollection $langs = null;

	private ?\Closure $attributeLoader = null;

	private ?\Closure $langLoader = null;

	private ?DateTime $dateCreate = null;

	private ?DateTime $dateInstall = null;

	public function __construct(
		private ?string $clientId,
		private ?string $code,
		private string $url,
		private array $scope,
		private AppOrigin $origin,
		private ?AppPricingModel $pricingModel = null,
		private ?AppLicenseState $licenseState = null,
		private bool $active = true,
		private bool $installed = false,
		private ?string $urlDemo = null,
		private ?string $urlInstall = null,
		private ?string $urlSettings = null,
		private ?string $version = null,
		private ?Date $dateFinish = null,
		private bool $isTrialed = false,
		private ?string $sharedKey = null,
		private ?string $clientSecret = null,
		private ?string $appName = null,
		private ?string $access = null,
		private ?string $applicationToken = null,
		private bool $mobile = false,
		private bool $userInstall = false,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getClientId(): ?string
	{
		return $this->clientId;
	}

	public function setClientId(string $clientId): self
	{
		$this->clientId = $clientId;

		return $this;
	}

	public function getCode(): ?string
	{
		return $this->code;
	}

	public function setCode(string $code): self
	{
		$this->code = $code;

		return $this;
	}

	public function isActive(): bool
	{
		return $this->active;
	}

	public function setActive(bool $active): self
	{
		$this->active = $active;

		return $this;
	}

	public function isInstalled(): bool
	{
		return $this->installed;
	}

	public function setInstalled(bool $installed): self
	{
		$this->installed = $installed;

		return $this;
	}

	public function getUrl(): string
	{
		return $this->url;
	}

	public function setUrl(string $url): self
	{
		$this->url = $url;

		return $this;
	}

	public function getUrlDemo(): ?string
	{
		return $this->urlDemo;
	}

	public function setUrlDemo(?string $urlDemo): self
	{
		$this->urlDemo = $urlDemo;

		return $this;
	}

	public function getUrlInstall(): ?string
	{
		return $this->urlInstall;
	}

	public function setUrlInstall(?string $urlInstall): self
	{
		$this->urlInstall = $urlInstall;

		return $this;
	}

	public function getUrlSettings(): ?string
	{
		return $this->urlSettings;
	}

	public function setUrlSettings(?string $urlSettings): self
	{
		$this->urlSettings = $urlSettings;

		return $this;
	}

	public function getVersion(): ?string
	{
		return $this->version;
	}

	public function setVersion(?string $version): self
	{
		$this->version = $version;

		return $this;
	}

	public function getScope(): array
	{
		return $this->scope;
	}

	public function setScope(array $scope): self
	{
		$this->scope = $scope;

		return $this;
	}

	public function getOrigin(): AppOrigin
	{
		return $this->origin;
	}

	public function setOrigin(AppOrigin $origin): self
	{
		$this->origin = $origin;

		return $this;
	}

	public function getPricingModel(): ?AppPricingModel
	{
		return $this->pricingModel;
	}

	public function setPricingModel(?AppPricingModel $pricingModel): self
	{
		$this->pricingModel = $pricingModel;

		return $this;
	}

	public function getLicenseState(): ?AppLicenseState
	{
		return $this->licenseState;
	}

	public function setLicenseState(?AppLicenseState $licenseState): self
	{
		$this->licenseState = $licenseState;

		return $this;
	}

	public function getDateFinish(): ?Date
	{
		return $this->dateFinish;
	}

	public function setDateFinish(?Date $dateFinish): self
	{
		$this->dateFinish = $dateFinish;

		return $this;
	}

	public function isTrialed(): bool
	{
		return $this->isTrialed;
	}

	public function setIsTrialed(bool $isTrialed): self
	{
		$this->isTrialed = $isTrialed;

		return $this;
	}

	public function getSharedKey(): ?string
	{
		return $this->sharedKey;
	}

	public function setSharedKey(?string $sharedKey): self
	{
		$this->sharedKey = $sharedKey;

		return $this;
	}

	public function getClientSecret(): ?string
	{
		return $this->clientSecret;
	}

	public function setClientSecret(?string $clientSecret): self
	{
		$this->clientSecret = $clientSecret;

		return $this;
	}

	public function getAppName(): ?string
	{
		return $this->appName;
	}

	public function setAppName(?string $appName): self
	{
		$this->appName = $appName;

		return $this;
	}

	public function getAccess(): ?string
	{
		return $this->access;
	}

	public function setAccess(string|array|null $access): self
	{
		if (is_array($access))
		{
			$access = empty($access) ? null : implode(',', $access);
		}

		$this->access = $access;

		return $this;
	}

	public function getApplicationToken(): ?string
	{
		return $this->applicationToken;
	}

	public function setApplicationToken(?string $applicationToken): self
	{
		$this->applicationToken = $applicationToken;

		return $this;
	}

	public function isMobile(): bool
	{
		return $this->mobile;
	}

	public function setMobile(bool $mobile): self
	{
		$this->mobile = $mobile;

		return $this;
	}

	public function isUserInstall(): bool
	{
		return $this->userInstall;
	}

	public function setUserInstall(bool $userInstall): self
	{
		$this->userInstall = $userInstall;

		return $this;
	}

	public function getDateCreate(): ?DateTime
	{
		return $this->dateCreate;
	}

	public function setDateCreate(?DateTime $dateCreate): self
	{
		$this->dateCreate = $dateCreate;

		return $this;
	}

	public function getDateInstall(): ?DateTime
	{
		return $this->dateInstall;
	}

	public function setDateInstall(?DateTime $dateInstall): self
	{
		$this->dateInstall = $dateInstall;

		return $this;
	}

	// region Lazy loading

	public function setAttributeLoader(\Closure $loader): self
	{
		$this->attributeLoader = $loader;

		return $this;
	}

	public function setLangLoader(\Closure $loader): self
	{
		$this->langLoader = $loader;

		return $this;
	}

	private function ensureAttributesLoaded(): void
	{
		if ($this->attributes === null && $this->externalAttributes === null)
		{
			if ($this->attributeLoader !== null && $this->id !== null)
			{
				[$system, $external] = ($this->attributeLoader)($this->id);
				$this->attributes = $system;
				$this->externalAttributes = $external;
				$this->attributeLoader = null;

				return;
			}
		}

		$this->attributes ??= new AppAttributeCollection();
		$this->externalAttributes ??= new AppAttributeCollection();
	}

	private function ensureLangsLoaded(): void
	{
		if ($this->langs !== null)
		{
			return;
		}

		if ($this->langLoader !== null && $this->id !== null)
		{
			$this->langs = ($this->langLoader)($this->id);
			$this->langLoader = null;

			return;
		}

		$this->langs = new AppLangCollection();
	}

	// endregion

	// region Langs

	/**
	 * @return AppLangCollection
	 */
	public function getLangs(): AppLangCollection
	{
		$this->ensureLangsLoaded();

		return $this->langs;
	}

	/**
	 * @param AppLang[] $langs
	 */
	public function setLangs(array $langs): self
	{
		throw new Main\NotImplementedException('Implement the setLangs() method');

	}

	public function getLang(string $languageId): ?AppLang
	{
		return $this->getLangs()[$languageId] ?? null;
	}

	public function setLang(string $languageId, string $menuName): self
	{
		$langs = $this->getLangs();
		$langItem = $langs->find(function (AppLang $appLang) use ($languageId)
		{
			return $appLang->getLanguageId() === $languageId;
		});
		if (!empty($langItem))
		{
			$langItem->setMenuName($menuName);
		}
		else
		{
			$langs->add(new AppLang(
				appId: $this->id ?? 0,
				languageId: $languageId,
				menuName: $menuName,
			));
		}

		return $this;
	}

	// endregion

	// region Attributes

	public function getAttributes(): AppAttributeCollection
	{
		$this->ensureAttributesLoaded();

		return $this->attributes;
	}

	public function setAttributes(AppAttributeCollection $attributes): self
	{
		$this->attributes = $attributes;

		return $this;
	}

	public function getAttribute(string $code): ?AppAttribute
	{
		return $this->getAttributes()->getByCode($code);
	}

	public function getAttributeValue(string $code): ?string
	{
		return $this->getAttribute($code)?->getValue();
	}

	public function setAttribute(string $code, ?string $value): self
	{
		$existing = $this->getAttribute($code);

		if ($existing !== null)
		{
			$existing->setValue($value);
		}
		else
		{
			$this->getAttributes()->add(new AppAttribute(
				appId: $this->id ?? 0,
				code: $code,
				value: $value,
			));
		}

		return $this;
	}

	public function removeAttribute(string $code): self
	{
		$this->attributes = $this->getAttributes()->filter(
			fn(AppAttribute $attr) => $attr->getCode() !== $code,
		);

		return $this;
	}

	public function getExternalAttributes(): AppAttributeCollection
	{
		$this->ensureAttributesLoaded();

		return $this->externalAttributes;
	}

	public function setExternalAttributes(AppAttributeCollection $attributes): self
	{
		$this->externalAttributes = $attributes;

		return $this;
	}

	public function getExternalAttribute(string $code): ?AppExternalAttribute
	{
		return $this->getExternalAttributes()->getByCode($code);
	}

	public function setExternalAttribute(string $code, ?string $value): self
	{
		$existing = $this->getExternalAttribute($code);

		if ($existing !== null)
		{
			$existing->setValue($value);
		}
		else
		{
			$this->getExternalAttributes()->add(new AppExternalAttribute(
				appId: $this->id,
				code: $code,
				value: $value,
			));
		}

		return $this;
	}

	public function removeExternalAttribute(string $code): self
	{
		$this->externalAttributes = $this->getExternalAttributes()->filter(
			fn(AppExternalAttribute $attr) => $attr->getCode() !== $code,
		);

		return $this;
	}

	// endregion
}
