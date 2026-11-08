<?php

namespace Bitrix\BIConnector\Controller\ExternalSource;

use Bitrix\BIConnector;
use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\ExternalSource;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceRestConnectorTable;
use Bitrix\BIConnector\ExternalSource\SourceManager;
use Bitrix\BIConnector\Superset\ActionFilter;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Crm;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class Source extends Controller
{
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';

	protected function getDefaultPreFilters(): array
	{
		$additionalFilters = [
			new ActionFilter\BIConstructorAccess(),
			new ActionFilter\WorkspaceAnalyticAccess(),
		];

		if (Loader::includeModule('intranet'))
		{
			$additionalFilters[] = new IntranetUser();
		}

		return [
			...parent::getDefaultPreFilters(),
			...$additionalFilters,
		];
	}

	public function changeActivityAction(int $id, string $moduleId): ?bool
	{
		$sourceResult = $this->getSource($id, $moduleId);
		if (!$sourceResult->isSuccess())
		{
			$this->addErrors($sourceResult->getErrors());

			return null;
		}

		$source = $sourceResult->getData()['source'];

		if ($source->getActive())
		{
			$source->setActive(false);
		}
		else
		{
			$source->setActive(true);
		}

		$saveResult = $source->save();
		if (!$saveResult->isSuccess())
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_NOT_SAVED');
			$this->addError(new UserFacingError($errorMessage));

			return null;
		}

		return true;
	}

	public function validateBeforeDeleteAction(int $id, string $moduleId): ?bool
	{
		if ($moduleId !== 'BI')
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_DELETE_ERROR_WRONG_MODULE');
			$this->addError(new UserFacingError($errorMessage));

			return null;
		}

		$source = BIConnector\ExternalSource\Internal\ExternalSourceTable::getById($id)->fetchObject();
		if (!$source)
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_NOT_FOUND');
			$this->addError(new UserFacingError($errorMessage));

			return null;
		}

		$validateError = ExternalSource\Internal\ExternalSourceTable::validateSourceBeforeDelete($id);
		if (!$validateError->isSuccess())
		{
			$this->addErrors($validateError->getErrors());

			return null;
		}

		return true;
	}

	public function deleteAction(int $id, string $moduleId): ?bool
	{
		if ($moduleId !== 'BI')
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_DELETE_ERROR_WRONG_MODULE');
			$this->addError(new UserFacingError($errorMessage));

			return null;
		}

		$deleteResult = SourceManager::deleteSource($id);
		if (!$deleteResult->isSuccess())
		{
			$this->addErrors($deleteResult->getErrors());

			return null;
		}

		return true;
	}

	public function checkExistingConnectionAction(int $sourceId): ?bool
	{
		$sourceEntity = BIConnector\ExternalSource\Internal\ExternalSourceTable::getById($sourceId)->fetchObject();
		if (!$sourceEntity)
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_NOT_FOUND');
			$this->addError(new UserFacingError($errorMessage));

			return null;
		}

		$source = ExternalSource\Source\Factory::getSource($sourceEntity->getEnumType(), $sourceEntity->getId());
		$settings = SourceManager::getSourceSettings($sourceEntity);

		$connectResult = $source->connect($settings);

		if (!$connectResult->isSuccess())
		{
			$this->addErrors($connectResult->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * Connecting to an external database must not be left to the interface check alone:
	 * a direct controller call bypasses the component guard.
	 */
	private function checkExternalDashboardConfigAccess(): bool
	{
		if (AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_EXTERNAL_DASHBOARD_CONFIG))
		{
			return true;
		}

		$this->addError(new UserFacingError(
			Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_ACCESS_DENIED'),
			self::ERROR_ACCESS_DENIED,
		));

		return false;
	}

	public function checkConnectionByDataAction(array $data): ?bool
	{
		if (!$this->checkExternalDashboardConfigAccess())
		{
			return null;
		}

		if (!isset($data['type']) || !$data['type'])
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_FIELDS_INCOMPLETE');
			$this->addError(new UserFacingError($errorMessage));

			return null;
		}

		$fieldsConfig = ExternalSource\SourceManager::getFieldsConfig();
		$configKey = $data['code'] ?? '';
		if (!isset($fieldsConfig[$configKey]))
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_TYPE_UNAVAILABLE');
			$this->addError(new UserFacingError($errorMessage));

			return null;
		}

		$requiredFields = $fieldsConfig[$configKey];
		$settings = ExternalSource\Internal\ExternalSourceSettingsTable::createCollection();
		foreach ($requiredFields as $requiredField)
		{
			if ($requiredField['required'] ?? true)
			{
				if (!isset($data[$requiredField['code']]) || !$data[$requiredField['code']])
				{
					$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_FIELDS_INCOMPLETE');
					$this->addError(new UserFacingError($errorMessage));

					return null;
				}
			}
			elseif (!isset($data[$requiredField['code']]) || $data[$requiredField['code']] === '')
			{
				continue;
			}

			$settingItem = ExternalSource\Internal\ExternalSourceSettingsTable::createObject();
			$settingItem
				->setName($requiredField['name'])
				->setType($requiredField['type'])
				->setCode($requiredField['code'])
				->setValue($data[$requiredField['code']])
			;
			$settings->add($settingItem);
		}

		$type = ExternalSource\Type::tryFrom($data['type']);
		if (!$type)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_UNKNOWN_TYPE', [
				'#CONNECTION_TYPE#' => htmlspecialcharsbx($data['type']),
			]) ?? ''));

			return null;
		}

		$source = ExternalSource\Source\Factory::getSource($type, 0);
		if ($source instanceof ExternalSource\Source\Rest)
		{
			$connectorId = (int)str_replace('rest_', '', $configKey);
			$connector = ExternalSourceRestConnectorTable::getByPrimary($connectorId)->fetchObject();

			if (!$connector)
			{
				$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_NOT_FOUND');
				$this->errorCollection[] = new UserFacingError($errorMessage);

				return null;
			}

			$source->setConnector($connector);
		}
		$connectionResult = $source->connect($settings);
		if (!$connectionResult->isSuccess())
		{
			$this->addErrors($connectionResult->getErrors());

			return null;
		}

		return true;
	}

	public function saveAction(array $data): ?array
	{
		if (!$this->checkExternalDashboardConfigAccess())
		{
			return null;
		}

		$sourceId = null;
		if (isset($data['id']))
		{
			$sourceId = (int)$data['id'];
		}

		if ($sourceId)
		{
			$saveResult = SourceManager::updateConnection($sourceId, $data);
		}
		else
		{
			$saveResult = SourceManager::addConnection($data);
		}

		if (!$saveResult->isSuccess())
		{
			$this->addErrors($saveResult->getErrors());

			return null;
		}

		return $saveResult->getData();
	}

	public function updateCommentAction(string $id, string $value): ?bool
	{
		[$id, $moduleId] = explode('.', $id);

		$sourceResult = $this->getSource((int)$id, $moduleId);
		if (!$sourceResult->isSuccess())
		{
			$this->addErrors($sourceResult->getErrors());

			return null;
		}

		$source = $sourceResult->getData()['source'];

		$source->setDescription($value);

		$saveResult = $source->save();
		if (!$saveResult->isSuccess())
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_NOT_SAVED');
			$this->addError(new UserFacingError($errorMessage));

			return null;
		}

		return true;
	}

	private function getSource(int $id, string $moduleId): Result
	{
		$result = new Result();
		$source = null;

		if ($moduleId === 'BI')
		{
			$source = BIConnector\ExternalSource\Internal\ExternalSourceTable::getById($id)->fetchObject();
		}
		elseif ($moduleId === 'CRM')
		{
			if (!Loader::includeModule('crm'))
			{
				$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_MODULE_UNAVAILABLE');
				$result->addError(new UserFacingError($errorMessage));

				return $result;
			}
			$source = Crm\Tracking\Internals\SourceTable::getById($id)->fetchObject();
		}

		if (!$source)
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SOURCE_ERROR_NOT_FOUND');
			$result->addError(new UserFacingError($errorMessage));

			return $result;
		}

		$result->setData(['source' => $source]);

		return $result;
	}
}
