<?php

namespace Bitrix\Bizproc\Integration\UI\EntitySelector;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Tab;

class AutomationTemplateProvider extends TemplateProvider
{
	protected const ENTITY_ID = 'bizproc-automation-template';
	protected const TAB_ID = 'automation-templates';

	public function __construct(array $options = [])
	{
		parent::__construct($options);
		$this->options = [];
	}

	protected function addTemplatesTab(Dialog $dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => static::TAB_ID,
			'title' => Loc::getMessage('BIZPROC_ENTITY_SELECTOR_TEMPLATES_TAB_AUTOMATION_TEMPLATES_TITLE'),
			'itemOrder' => ['sort' => 'asc nulls last'],
			'stub' => true,
			'icon' => [
				'default' => 'o-robot',
				'selected' => 's-robot',
			],
		]));
	}

	protected function getDefaultTemplateFilter(): ConditionTree
	{
		return (
			\Bitrix\Main\ORM\Query\Query::filter()
				->where('ACTIVE', 'Y')
				->where('AUTO_EXECUTE', \CBPDocumentEventType::Automation)
		);
	}
}
