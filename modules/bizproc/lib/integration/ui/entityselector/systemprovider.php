<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\UI\EntitySelector;

use Bitrix\Bizproc\Internal\Service\Feature\AiAgentsFeature;
use Bitrix\Bizproc\Workflow\Template\SystemValueCatalog;

use Bitrix\Main\DI\ServiceLocator;
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
		foreach (static::getSystemValues() as $value)
		{
			$children[] = [
				'id' => $value['expression'],
				'entityId' => static::ENTITY_ID,
				'entityType' => $value['object'],
				'title' => $value['label'],
			];
		}

		$item->addChildren($children);

		return [$item];
	}

	public static function getSystemValues(): array
	{
		$systemValues = SystemValueCatalog::getSystemValues();

		if (ServiceLocator::getInstance()->get(AiAgentsFeature::class)?->isAvailable())
		{
			$systemValues[] = SystemValueCatalog::getAiAgentsSystemValue();
		}

		$result = [];
		foreach ($systemValues as [$object, $field, $labelPhraseKey, $type])
		{
			$result[] = [
				'object' => $object,
				'field' => $field,
				'expression' => self::makeSystemId($object, $field),
				'label' => (string)Loc::getMessage($labelPhraseKey),
				'type' => $type,
			];
		}

		return $result;
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

	private static function makeSystemId(string $type, string $field): string
	{
		return "{={$type}:{$field}}";
	}

	protected function getCurrentUserId(): int
	{
		return (int)(CurrentUser::get()->getId());
	}
}
