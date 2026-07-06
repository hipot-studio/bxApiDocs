<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Mapper;

use Bitrix\Rest\Internal\Entity\Application\AppAttributeCollection;
use Bitrix\Rest\Internal\Entity\Application\AppCollection;
use Bitrix\Rest\Internal\Entity\Application\AppExternalAttribute;
use Bitrix\Rest\Internal\Entity\Application\AppLang;
use Bitrix\Rest\Internal\Model\AppStatus;
use Bitrix\Rest\Internal\Model\AppLangTable;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\EO_App;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Entity\Application\AppAttribute;
use Bitrix\Rest\Internal\Model\AppAttributeTable;
use Bitrix\Rest\EO_App_Collection;
use Bitrix\Rest\Internal\Model\EO_AppAttribute;
use Bitrix\Rest\Internal\Model\EO_AppAttribute_Collection;
use Bitrix\Rest\Internal\Model\EO_AppLang;
use Bitrix\Rest\Internal\Model\EO_AppLang_Collection;

class AppMapper
{
	public function convertFromOrm(
		EO_App $ormModel,
		EO_AppAttribute_Collection|\Closure|null $attributes = null,
		EO_AppLang_Collection|\Closure|null $langs = null,
	): App
	{
		$status = AppStatus::from($ormModel->getStatus());

		$app = new App(
			clientId: $ormModel->getClientId(),
			code: $ormModel->getCode(),
			url: $ormModel->getUrl() ?? '',
			scope: array_filter(explode(',', $ormModel->getScope() ?? '')),
			origin: $status->getOrigin(),
			pricingModel: $status->getPricingModel(),
			licenseState: $status->getLicenseState(),
			active: $ormModel->getActive(),
			installed: $ormModel->getInstalled(),
			urlDemo: $ormModel->getUrlDemo(),
			urlInstall: $ormModel->getUrlInstall(),
			urlSettings: $ormModel->getUrlSettings(),
			version: $ormModel->getVersion(),
			dateFinish: $ormModel->getDateFinish(),
			isTrialed: $ormModel->getIsTrialed(),
			sharedKey: $ormModel->getSharedKey(),
			clientSecret: $ormModel->getClientSecret(),
			appName: $ormModel->getAppName(),
			access: $ormModel->getAccess(),
			applicationToken: $ormModel->getApplicationToken(),
			mobile: $ormModel->getMobile(),
			userInstall: $ormModel->getUserInstall(),
		);

		$app->setId($ormModel->getId());

		if ($ormModel->getDateCreate())
		{
			$app->setDateCreate($ormModel->getDateCreate());
		}

		if ($ormModel->getDateInstall())
		{
			$app->setDateInstall($ormModel->getDateInstall());
		}

		if ($attributes instanceof EO_AppAttribute_Collection)
		{
			[$systemCollection, $externalCollection] = $this->splitAttributeCollectionFromOrm($attributes);
			$app->setAttributes($systemCollection);
			$app->setExternalAttributes($externalCollection);
		}
		elseif ($attributes instanceof \Closure)
		{
			$app->setAttributeLoader($attributes);
		}

		if ($langs instanceof EO_AppLang_Collection)
		{
			foreach ($langs as $ormLang)
			{
				$app->setLang($ormLang->getLanguageId(), $ormLang->getMenuName() ?? '');
			}
		}
		elseif ($langs instanceof \Closure)
		{
			$app->setLangLoader($langs);
		}

		return $app;
	}

	public function convertToOrm(App $entity): EO_App
	{
		$ormModel = $entity->getId()
			? EO_App::wakeUp($entity->getId())
			: AppTable::createObject()
		;

		$ormModel
			->setClientId($entity->getClientId())
			->setCode($entity->getCode())
			->setActive($entity->isActive())
			->setInstalled($entity->isInstalled())
			->setUrl($entity->getUrl())
			->setScope(implode(',', $entity->getScope()))
			->setStatus(AppStatus::fromComponents(
				$entity->getOrigin(),
				$entity->getPricingModel(),
				$entity->getLicenseState(),
			)->value)
			->setUrlDemo($entity->getUrlDemo())
			->setUrlInstall($entity->getUrlInstall())
			->setUrlSettings($entity->getUrlSettings())
			->setVersion($entity->getVersion())
			->setDateFinish($entity->getDateFinish())
			->setIsTrialed($entity->isTrialed())
			->setSharedKey($entity->getSharedKey())
			->setClientSecret($entity->getClientSecret())
			->setAppName($entity->getAppName())
			->setAccess($entity->getAccess())
			->setApplicationToken($entity->getApplicationToken())
			->setMobile($entity->isMobile())
			->setUserInstall($entity->isUserInstall())
		;

		return $ormModel;
	}

	public function convertAttributeFromOrm(EO_AppAttribute $ormModel): AppAttribute
	{
		$attribute = new AppAttribute(
			appId: $ormModel->getAppId(),
			code: $ormModel->getCode(),
			value: $ormModel->getValue(),
			type: $ormModel->getType() ?? AppAttribute::TYPE_SYSTEM,
		);

		$attribute
			->setId($ormModel->getId())
			->setDateCreate($ormModel->getDateCreate())
		;

		return $attribute;
	}

	public function convertExternalAttributeFromOrm(EO_AppAttribute $ormModel): AppExternalAttribute
	{
		$attribute = new AppExternalAttribute(
			appId: $ormModel->getAppId(),
			code: $ormModel->getCode(),
			value: $ormModel->getValue(),
		);

		$attribute->setId($ormModel->getId());

		return $attribute;
	}

	/**
	 * @return array{AppAttributeCollection, AppAttributeCollection}
	 */
	public function splitAttributeCollectionFromOrm(EO_AppAttribute_Collection $ormCollection): array
	{
		$systemCollection = new AppAttributeCollection();
		$externalCollection = new AppAttributeCollection();

		foreach ($ormCollection as $ormModel)
		{
			$type = $ormModel->getType() ?? AppAttribute::TYPE_SYSTEM;

			if ($type === AppAttribute::TYPE_EXTERNAL)
			{
				$externalCollection->add($this->convertExternalAttributeFromOrm($ormModel));
			}
			else
			{
				$systemCollection->add($this->convertAttributeFromOrm($ormModel));
			}
		}

		return [$systemCollection, $externalCollection];
	}

	public function convertAttributeToOrm(AppAttribute $entity): EO_AppAttribute
	{
		$ormModel = $entity->getId()
			? EO_AppAttribute::wakeUp($entity->getId())
			: AppAttributeTable::createObject()
		;

		$ormModel
			->setAppId($entity->getAppId())
			->setCode($entity->getCode())
			->setValue($entity->getValue())
			->setType($entity->getType())
		;

		return $ormModel;
	}

	public function convertLangFromOrm(EO_AppLang $ormModel): AppLang
	{
		$lang = new AppLang(
			appId: $ormModel->getAppId(),
			languageId: $ormModel->getLanguageId(),
			menuName: $ormModel->getMenuName() ?? '',
		);

		$lang->setId($ormModel->getId());

		return $lang;
	}

	public function convertLangToOrm(AppLang $entity): EO_AppLang
	{
		$ormModel = $entity->getId()
			? EO_AppLang::wakeUp($entity->getId())
			: AppLangTable::createObject()
		;

		$ormModel
			->setAppId($entity->getAppId())
			->setLanguageId($entity->getLanguageId())
			->setMenuName($entity->getMenuName())
		;

		return $ormModel;
	}

	public function convertCollectionFromOrm(
		EO_App_Collection $ormCollection,
		EO_AppAttribute_Collection|\Closure|null $attributes = null,
		EO_AppLang_Collection|\Closure|null $langs = null,
	): AppCollection
	{
		$collection = new AppCollection();

		$ormAttributes = [];
		if ($attributes instanceof EO_AppAttribute_Collection)
		{
			foreach ($attributes as $attr)
			{
				$ormAttributes[$attr->getAppId()] ??= new EO_AppAttribute_Collection();
				$ormAttributes[$attr->getAppId()]->add($attr);
			}
		}
		$ormLangs = [];
		if ($langs instanceof EO_AppLang_Collection)
		{
			foreach ($langs as $lang)
			{
				$ormLangs[$lang->getAppId()] ??= new EO_AppLang_Collection();
				$ormLangs[$lang->getAppId()]->add($lang);
			}
		}

		foreach ($ormCollection as $ormModel)
		{
			$appId = $ormModel->getId();
			$app = $this->convertFromOrm(
				$ormModel,
				$attributes instanceof \Closure ? $attributes : ($ormAttributes[$appId] ?? null),
				$langs instanceof \Closure ? $langs : ($ormLangs[$appId] ?? null),
			);

			$collection->add($app);
		}

		return $collection;
	}
}
