<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\UI\EntitySelector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\Tab;

class SystemProvider extends BaseProvider
{
	protected const TAB_ID = 'bizproc-system-tab';
	protected const ENTITY_ID = 'bizproc-system';
	protected const ENTITY_TYPE_SYSTEM = 'System';
	protected const ENTITY_TYPE_WORKFLOW = 'Workflow';
	protected const ENTITY_TYPE_USER = 'User';
	protected const ENTITY_TYPE_TEMPLATE = 'Template';
	protected const ENTITY_TYPE_FUNCTION = 'Function';

	public function __construct(array $options = [])
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		return $this->getCurrentUserId() > 0;
	}

	public function getItems(array $ids): array
	{
		// todo: something

		return [];
	}

	public function fillDialog(Dialog $dialog): void
	{
		$this->addTab($dialog);

		$dialog->addItems($this->getSystemItems());
		$dialog->addItems($this->getFunctionItems());
	}

	protected function addTab(Dialog $dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => static::TAB_ID,
			'title' => Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_TAB'),
			'itemOrder' => ['sort' => 'asc nulls last'],
			'icon' => 'sigma-summ',
		]));
	}

	protected function getSystemItems(): array
	{
		$item = new Item([
			'id' => static::ENTITY_TYPE_SYSTEM,
			'entityId' => static::ENTITY_ID,
			'entityType' => static::ENTITY_TYPE_SYSTEM,
			'title' => Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_TYPE_SYSTEM'),
			'tabs' => static::TAB_ID,
		]);

		$children = [];
		foreach ($this->getSystemFields() as [$title, $entityType, $fieldId])
		{
			$children[] = [
				'id' => $this->makeSystemId($entityType, $fieldId),
				'entityId' => static::ENTITY_ID,
				'entityType' => $entityType,
				'title' => $title,
			];
		}

		$item->addChildren($children);

		return [$item];
	}

	private function getSystemFields(): array
	{
		return [
			[
				Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_WORKFLOW_ID'),
				static::ENTITY_TYPE_WORKFLOW,
				'ID',
			],
			[
				Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_WORKFLOW_TEMPLATE_ID'),
				static::ENTITY_TYPE_WORKFLOW,
				'TemplateId',
			],
			[
				Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_TARGET_USER'),
				static::ENTITY_TYPE_TEMPLATE,
				'TargetUser',
			],
			[
				Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_USER_ID'),
				static::ENTITY_TYPE_USER,
				'ID',
			],
			[
				Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_NOW'),
				static::ENTITY_TYPE_SYSTEM,
				'Now',
			],
			[
				Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_NOW_LOCAL'),
				static::ENTITY_TYPE_SYSTEM,
				'NowLocal',
			],
			[
				Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_DATE'),
				static::ENTITY_TYPE_SYSTEM,
				'Date',
			],
			[
				Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_EOL'),
				static::ENTITY_TYPE_SYSTEM,
				'Eol',
			],
			[
				Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_HOST_URL'),
				static::ENTITY_TYPE_SYSTEM,
				'HostUrl',
			],
		];
	}

	protected function getFunctionItems(): array
	{
		$item = new Item([
			'id' => static::ENTITY_TYPE_FUNCTION,
			'entityId' => static::ENTITY_ID,
			'entityType' => static::ENTITY_TYPE_FUNCTION,
			'title' => Loc::getMessage('BIZPROC_ENTITY_SELECTOR_SYSTEM_TAB'),
			'tabs' => static::TAB_ID,
		]);

		$children = [];
		$functions = \Bitrix\Bizproc\Calc\Functions::getList();

		foreach ($functions as $name => ['description' => $description])
		{
			$children[] = [
				'id' => "{{={$name}()}}",
				'entityId' => static::ENTITY_ID,
				'entityType' => static::ENTITY_TYPE_FUNCTION,
				'title' => $name,
				'subtitle' => $description,
			];
		}

		$item->addChildren($children);

		return [$item];
	}

	private function makeSystemId(string $type, string $field): string
	{
		return "{={$type}:{$field}}";
	}

	protected function getCurrentUserId(): int
	{
		return (int)(CurrentUser::get()->getId());
	}
}
