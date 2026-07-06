<?php

namespace Bitrix\Rest\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Rest\Marketplace;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Rest\PlacementTable;

class Application extends Controller
{
	public function installAction($code, $version = false, $checkHash = false, $installHash = false, $from = null)
	{
		$result = Marketplace\Application::install($code, $version, $checkHash, $installHash, $from);
		if ($result['errorDescription'])
		{
			$result['error_description'] = $result['errorDescription'];
		}

		return $result;
	}

	public function uninstallAction($code, $clean = 'N', $from = null)
	{
		return Marketplace\Application::uninstall($code, ($clean === 'Y'), $from);
	}

	public function reinstallAction($id)
	{
		return Marketplace\Application::reinstall($id);
	}

	public function setRightsAction($appId, $rights)
	{
		return Marketplace\Application::setRights($appId, $rights);
	}

	public function getRightsAction($appId)
	{
		return Marketplace\Application::getRights($appId);
	}

	public function embeddingListAction(string $placement, ?int $userId = null): ?array
	{
		if (!preg_match('/^[A-Z][A-Z0-9_]+$/', $placement))
		{
			$this->addError(new Error('Wrong placement code.', 'ERROR_PLACEMENT_WRONG_CODE'));
			return null;
		}

		return PlacementTable::getHandlersList(placement: $placement, userId: $userId);
	}

	/**
	 * Returns default pre-filters for action.
	 * @return array
	 */
	protected function getDefaultPreFilters()
	{
		$defaultPreFilters = parent::getDefaultPreFilters();
		$defaultPreFilters[] = new ActionFilter\Scope(ActionFilter\Scope::NOT_REST);

		return $defaultPreFilters;
	}
}