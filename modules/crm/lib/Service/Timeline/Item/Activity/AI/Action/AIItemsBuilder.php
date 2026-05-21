<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action;

use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\AssociatedEntityModel;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button as FooterButton;
use Bitrix\Crm\Service\Timeline\Layout\Menu\MenuItem;

final class AIItemsBuilder
{
	public const PRIMARY_BUTTON = 'aiPrimaryScenarioButton';
	public const SECONDARY_BUTTON = 'aiSecondaryScenarioButton';

	private int $activityId;
	private AssociatedEntityModel $model;
	private Context $context;

	/**
	 * @var AIAction[]
	 */
	private array $actions = [];

	private function __construct(int $activityId, Context $context, AssociatedEntityModel $model)
	{
		$this->activityId = $activityId;
		$this->context = $context;
		$this->model = $model;
	}

	/**
	 * Creates instance of builder with required params for building AI action buttons and menu items.
	 *
	 * @param int $activityId Activity identifier for which buttons and menu items will be built
	 * @param Context $context Context of timeline item, contains entity type and id
	 * @param AssociatedEntityModel $model Associated entity model with additional data for building buttons and menu items, e.g. call recording file for call activity
	 *
	 * @return self
	 */
	public static function create(int $activityId, Context $context, AssociatedEntityModel $model): self
	{
		return new self($activityId, $context, $model);
	}

	/**
	 * Adds action by scenario name.
	 *
	 * @param string $scenario Scenario identifier from Scenario class
	 *
	 * @return self
	 */
	public function withScenario(string $scenario): self
	{
		$action = AIActionRegistry::create(
			$scenario,
			$this->activityId,
			$this->context,
			$this->model
		);
		if ($action)
		{
			$this->actions[] = $action;
		}

		return $this;
	}

	/**
	 * @return array<string, FooterButton>
	 */
	public function getButtons(): array
	{
		$buttons = [];
		$index = 0;

		foreach ($this->actions as $action)
		{
			if ($action->isHidden() || $action->isMenuOnly())
			{
				continue;
			}

			$key = $index === 0 ? self::PRIMARY_BUTTON : self::SECONDARY_BUTTON;
			$buttons[$key] = $action->toButton();
			$index++;

			if ($index >= 2)
			{
				break; // only two buttons are allowed, the other will be ignored
			}
		}

		return $buttons;
	}

	/**
	 * @return array<int, MenuItem>
	 */
	public function getMenuItems(): array
	{
		$menuItems = [];

		foreach ($this->actions as $action)
		{
			$menuItem = $action->toMenuItem();
			if ($menuItem !== null)
			{
				$menuItems[] = $menuItem;
			}
		}

		return $menuItems;
	}

	/**
	 * @return AIAction[]
	 */
	public function getActions(): array
	{
		return $this->actions;
	}
}
