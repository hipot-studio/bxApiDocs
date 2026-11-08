<?php

use Bitrix\Crm\Feature\Entity\FeatureRepository;
use Bitrix\Crm\Tour\TourRepository;
use Bitrix\Main\ModuleManager;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CrmFeaturesList extends CBitrixComponent implements \Bitrix\Main\Engine\Contract\Controllerable, \Bitrix\Main\Errorable
{
	protected ?\Bitrix\Main\ErrorCollection $errors;
	private FeatureRepository $featureRepository;
	private TourRepository $tourRepository;

	public function __construct($component = null)
	{
		parent::__construct($component);

		\Bitrix\Main\Loader::requireModule('crm');
		$this->errors = new \Bitrix\Main\ErrorCollection();

		$this->featureRepository = new FeatureRepository();
		$this->tourRepository = new TourRepository();
	}

	public function executeComponent()
	{
		if (!\Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->isCrmAdmin())
		{
			LocalRedirect('/crm/configs/');
		}

		if ($this->request->get('enableFeature'))
		{
			$this->enableFeature((string)$this->request->get('enableFeature'));
			LocalRedirect('/crm/configs/');
		}
		elseif ($this->request->get('disableFeature'))
		{
			$this->disableFeature((string)$this->request->get('disableFeature'));
			LocalRedirect('/crm/configs/');
		}
		elseif ($this->request->get('resetTour'))
		{
			$this->resetTour((string)$this->request->get('resetTour'));
			LocalRedirect('/crm/configs/');
		}

		if (!$this->checkAccess())
		{
			LocalRedirect('/crm/configs/');
		}
		$this->arResult['mode'] = $this->getCurrentMode();

		switch ($this->arResult['mode'])
		{
			case 'features':
				$this->arResult['features'] = $this->prepareFeatures();
				break;
			case 'tours':
				$this->arResult['toursEnabled'] = \Bitrix\Main\Config\Option::get('crm.tour', 'HIDE_ALL_TOURS', 'N') === 'N';
				$this->arResult['tours'] = $this->tourRepository->getAllByCategory();
				break;
		}

		$this->includeComponentTemplate($this->arResult['mode']);
	}

	public function enableFeatureAction(string $featureId): ?bool
	{
		return $this->switchFeatureByAction($featureId, true);
	}

	public function disableFeatureAction(string $featureId): ?bool
	{
		return $this->switchFeatureByAction($featureId, false);
	}

	public function enableToursAction()
	{
		if (!$this->checkAccess())
		{
			$this->errors->setError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return null;
		}

		\Bitrix\Main\Config\Option::set('crm.tour', 'HIDE_ALL_TOURS', 'N');

		return true;
	}

	public function disableToursAction()
	{
		if (!$this->checkAccess())
		{
			$this->errors->setError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return null;
		}

		\Bitrix\Main\Config\Option::set('crm.tour', 'HIDE_ALL_TOURS', 'Y');

		return true;
	}

	public function resetTourAction(string $tourId)
	{
		if (!$this->checkAccess())
		{
			$this->errors->setError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return null;
		}

		$tour = $this->tourRepository->getById($tourId);
		if ($tour)
		{
			CUserOptions::DeleteOptionsByName($tour['optionCategory'], $tour['optionName']);

			return true;
		}
		else
		{
			$this->errors->setError(\Bitrix\Crm\Controller\ErrorCode::getNotFoundError());

			return null;
		}
	}

	public function resetAllToursAction()
	{
		if (!$this->checkAccess())
		{
			$this->errors->setError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return null;
		}

		$tours = $this->tourRepository->getAllTours();
		foreach ($tours as $tour)
		{
			CUserOptions::DeleteOptionsByName($tour['optionCategory'], $tour['optionName']);
		}

		return true;
	}

	public function configureActions()
	{
	}

	private function prepareFeatures(): array
	{
		return $this->featureRepository->getAllByCategory();
	}

	protected function checkAccess(): bool
	{
		if (!\Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->isCrmAdmin())
		{
			return false;
		}
		if (\Bitrix\Crm\Settings\Crm::isEtalon() || ModuleManager::isModuleInstalled('bxtest') || ModuleManager::isModuleInstalled('updateserverlight'))
		{
			return true;
		}

		return false;
	}

	/**
	 * Returns the effective state after a completed attempt: the core engine may keep the previous
	 * value without raising an exception, so a completed call does not mean an applied change.
	 * Returns null when the attempt did not complete, and the client then restores the previous
	 * switch position. A hook throwing after the engine has already stored the value falls into
	 * that branch too, so the restored position can lag behind the stored one until a reload.
	 */
	private function switchFeatureByAction(string $featureId, bool $enabled): ?bool
	{
		if (!$this->checkAccess())
		{
			$this->errors->setError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return null;
		}

		if (!$this->featureRepository->has($featureId))
		{
			$this->errors->setError(\Bitrix\Crm\Controller\ErrorCode::getNotFoundError());

			return null;
		}

		try
		{
			if ($enabled)
			{
				$this->featureRepository->enable($featureId);
			}
			else
			{
				$this->featureRepository->disable($featureId);
			}

			return $this->featureRepository->isEnabled($featureId);
		}
		catch (\Throwable $exception)
		{
			\Bitrix\Crm\Service\Container::getInstance()->getLogger('Features')->error(
				'Cannot switch CRM feature',
				[
					'featureId' => $featureId,
					'enabled' => $enabled,
					'exception' => $exception,
				],
			);

			$this->errors->setError(\Bitrix\Crm\Controller\ErrorCode::getGeneralError());

			return null;
		}
	}

	private function enableFeature(string $featureId): void
	{
		$this->switchFeatureBySecretLink($featureId, true);
	}

	private function disableFeature(string $featureId): void
	{
		$this->switchFeatureBySecretLink($featureId, false);
	}

	private function switchFeatureBySecretLink(string $featureId, bool $enabled): void
	{
		if (!$this->featureRepository->isSwitchBySecretLinkAllowed($featureId))
		{
			return;
		}

		try
		{
			if ($enabled)
			{
				$this->featureRepository->enable($featureId);
			}
			else
			{
				$this->featureRepository->disable($featureId);
			}
		}
		catch (\Throwable $exception)
		{
			\Bitrix\Crm\Service\Container::getInstance()->getLogger('Features')->error(
				'Cannot switch CRM feature by secret link',
				[
					'featureId' => $featureId,
					'enabled' => $enabled,
					'exception' => $exception,
				],
			);
		}
	}

	private function resetTour(string $tourId): void
	{
		$tour = $this->tourRepository->getById($tourId);
		if (!$tour)
		{
			return;
		}

		CUserOptions::DeleteOptionsByName($tour['optionCategory'], $tour['optionName']);

		$className = '\\Bitrix\\Crm\\Tour\\' . str_replace('/', '\\', $tour['id']);
		if (method_exists($className, 'getInstance'))
		{
			$className::getInstance()->onReset();
		}
	}

	private function getCurrentMode(): string
	{
		$valueFromRequest = $this->request->get('expertMode');
		if (in_array($valueFromRequest, ['features', 'tours'], true))
		{
			\Bitrix\Main\Config\Option::set('crm', 'expertMode', $valueFromRequest);

			return $valueFromRequest;
		}

		return \Bitrix\Main\Config\Option::get('crm', 'expertMode', 'features');
	}

	public function getErrors(): array
	{
		return $this->errors->toArray();
	}

	public function getErrorByCode($code): ?\Bitrix\Main\Error
	{
		return $this->errors->getErrorByCode($code);
	}
}
