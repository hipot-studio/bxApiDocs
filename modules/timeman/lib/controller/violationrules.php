<?php
namespace Bitrix\Timeman\Controller;

use Bitrix\Timeman\Form\Schedule\ViolationForm;
use Bitrix\Main\Engine\Controller;
use Bitrix\Timeman\Repository\Schedule\ViolationRulesRepository;
use Bitrix\Timeman\Service\BaseServiceResult;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\UseCase\Schedule\ViolationRules as ViolationRulesHandler;
use Bitrix\Timeman\V2\Infrastructure\Controller\ActionFilter\ToolEnabledFilter;

class ViolationRules extends Controller
{
	protected function getDefaultPreFilters()
	{
		return [
			new ToolEnabledFilter(),
			...parent::getDefaultPreFilters(),
		];
	}

	/**
	 * @var ViolationRulesRepository
	 */
	private $violationRulesRepository;

	protected function init()
	{
		$this->violationRulesRepository = DependencyManager::getInstance()->getViolationRulesRepository();
		parent::init();
	}

	public function saveAction()
	{
		$violationForm = new ViolationForm();
		if ($violationForm->load($this->getRequest()) && $violationForm->validate())
		{
			if ($violationForm->id > 0)
			{
				$result = (new ViolationRulesHandler\Update\Handler())->handle($violationForm);
			}
			else
			{
				$result = (new ViolationRulesHandler\Create\Handler())->handle($violationForm);
			}
			if (BaseServiceResult::isSuccessResult($result))
			{
				return $result->getViolationRules()->collectValues();
			}
			$this->addErrors($result->getErrors());
			return [];
		}
		$this->addErrors($violationForm->getErrors());
		return [];
	}
}
