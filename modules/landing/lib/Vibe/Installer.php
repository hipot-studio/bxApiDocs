<?php

namespace Bitrix\Landing\Vibe;

use Bitrix\AI\Integration;
use Bitrix\Landing;
use Bitrix\Landing\Metrika;
use Bitrix\Landing\Site\Type;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Configuration\Action\Import;
use Bitrix\Rest\Marketplace;

/**
 * Manage Vibe site and pages
 */
class Installer
{
	private const DEFAULT_TEMPLATE = 'empty';

	private Vibe $vibe;
	private int $siteId;

	public function __construct(Vibe $vibe)
	{
		if ($vibe->getSiteId() === null)
		{
			throw new ObjectPropertyException('Vibe have not created site');
		}

		$this->vibe = $vibe;
		$this->siteId = $vibe->getSiteId();
	}

	public function createDemoPage(): ?int
	{
		$result = Landing\Landing::addByTemplate(
			$this->siteId,
			self::DEFAULT_TEMPLATE,
			[
				'SITE_TYPE' => Type::SCOPE_CODE_VIBE,
			]
		);
		if (
			!$result->isSuccess()
			|| !$result->getId()
		)
		{
			return null;
		}

		return (int)$result->getId();
	}

	public function createPageByTemplate(Templates $code): ?int
	{
		if (!Loader::includeModule('rest'))
		{
			return false;
		}

		$appCode = TemplateRegions::resolve($code);
		if (!$appCode)
		{
			return null;
		}

		$metrika = new Metrika\Metrika(
			Metrika\Categories::Vibe,
			Metrika\Events::createTemplateApi,
			Metrika\Tools::Vibe,
		);
		$metrika->setParam(1, 'templateCode', $code->value);
		$metrika->setParam(2, 'chapter', $this->vibe->getModuleId() . '-' . $this->vibe->getEmbedId());

		$app = AppTable::getByClientId($appCode);
		$isAppInstalled =
			!empty($app['ACTIVE'])
			&& $app['ACTIVE'] === 'Y'
			&& !empty($app['INSTALLED'])
			&& $app['INSTALLED'] === 'Y';
		if (!$isAppInstalled)
		{
			$installResult = Marketplace\Application::install($appCode);
			if (
				!isset($installResult['success'])
				|| !$installResult['success']
				|| isset($installResult['error'])
			)
			{
				$metrika->setError($installResult['error'], Metrika\Statuses::ErrorMarket)->send();

				return null;
			}
		}

		$appSite = Marketplace\Client::getSiteList([
			'code' => $appCode,
			'siteType' => \Bitrix\Landing\Site\Scope\Vibe::getScopeIdForTransfer(),
		]);
		$zipId = (int)($appSite['ITEMS'][0]['ID'] ?? 0);
		if ($zipId <= 0)
		{
			$metrika->setError('Wrong_zip_id', Metrika\Statuses::ErrorMarket)->send();

			return null;
		}

		ob_start();
		/** @var \CRestConfigurationImportComponent $importComponent */
		$componentName = 'bitrix:rest.configuration.import';
		$className = \CBitrixComponent::includeComponentClass($componentName);
		$importComponent = new $className;
		$importComponent->initComponent($componentName);
		$importComponent->arParams = [
			'ZIP_ID' => $zipId,
			'ADDITIONAL' => [
				'siteId' => $this->siteId,
			],
			'MODE' => 'ZIP',
			'SET_TITLE' => 'Y',
		];
		$importComponent->executeComponent();
		ob_end_clean();

		$importId = $importComponent->arResult['IMPORT_PROCESS_ID'] ?? null;
		if (!$importId)
		{
			return null;
		}

		$importId = (int)$importId;
		$import = new Import($importId);
		$newLandingId = null;
		while (true)
		{
			if (!$newLandingId)
			{
				$ratio = $import->getSetting()->get('SETTING_RATIO');
				if (
					$ratio
					&& isset($ratio['LANDING']['specialPages']['LANDING_ID_INDEX'])
				)
				{
					$landingIdByTemplate = $ratio['LANDING']['specialPages']['LANDING_ID_INDEX'];
					$newLandingId = $ratio['LANDING']['landings'][$landingIdByTemplate] ?? null;
				}
			}

			$data = $import->get();
			if ($data['status'] !== 'F' && $data['status'] !== 'E' && $data['status'] !== 'U')
			{
				Import::runAgent($importId);
			}
			else
			{
				break;
			}
		}

		$metrika->send();

		return $newLandingId;
	}
}