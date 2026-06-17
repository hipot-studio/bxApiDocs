<?php

namespace Bitrix\Crm\Service\Timeline\Item\Bizproc;

use Bitrix\Crm\Service\Timeline\Item\Configurable;
use Bitrix\Crm\Service\Timeline\Layout\Body;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockFactory;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockWithTitle;
use Bitrix\Crm\Service\Timeline\Layout\Common;
use Bitrix\Bizproc\UI\Helpers\DurationFormatter;
use Bitrix\Main\Localization\Loc;

abstract class Base extends Configurable
{
	use Workflow;

	abstract protected function getBizprocTypeId(): string;

	final public function getType(): string
	{
		return 'Bizproc' . $this->getBizprocTypeId();
	}

	final public function getIconCode(): ?string
	{
		return Common\Icon::BIZPROC;
	}

	public function getLogo(): ?Body\Logo
	{
		return Common\Logo::getInstance(Common\Logo::BIZPROC)
			->createLogo()
			?->setInCircle()
			?->setAdditionalIconType(Body\Logo::ICON_TYPE_DEFAULT)
		;
	}

	public function getContentBlocks(): ?array
	{
		$result = [];

		$settings = $this->getModel()->getSettings();
		$workflowId = $settings['WORKFLOW_ID'] ?? null;
		$templateName = $settings['WORKFLOW_TEMPLATE_NAME'] ?? null;

		$processNameBlock = $this->buildProcessNameBlock($templateName, $workflowId);
		if (isset($processNameBlock))
		{
			$result['processNameBlock'] = $processNameBlock;
		}

		$createdTimeBlock = $this->buildCreatedTimeBlock();
		if (isset($createdTimeBlock))
		{
			$result['createdTimeBlock'] = $createdTimeBlock;
		}

		return $result;
	}

	public function getButtons(): array
	{
		$workflowId = $this->getModel()->getSettings()['WORKFLOW_ID'] ?? null;
		if (empty($workflowId))
		{
			return [];
		}

		return [
			'open' => $this->createOpenButton($workflowId)->setState(!$this->isBizprocEnabled() ? 'hidden' : null),
		];
	}

	final public function needShowNotes(): bool
	{
		return true;
	}

	private function buildCreatedTimeBlock(): ?ContentBlock
	{
		$textOrLink = ContentBlockFactory::createTextOrLink($this->getWorkflowStartDurationText(), null);

		return (new ContentBlockWithTitle())
			->setTitle(Loc::getMessage('CRM_TIMELINE_BIZPROC_EXEC_TITLE') ?? '')
			->setContentBlock($textOrLink)
			->setInline()
		;
	}

	private function getWorkflowStartDurationText(): string
	{
		$fallbackText = Loc::getMessage('CRM_TIMELINE_BIZPROC_EXEC_TIME') ?? '';

		if (!$this->isBizprocEnabled())
		{
			return $fallbackText;
		}

		$duration = $this->resolveWorkflowStartDurationFromSettings();
		if ($duration === null)
		{
			return $fallbackText;
		}

		if ($duration === 0)
		{
			return DurationFormatter::format($duration) ?? $fallbackText;
		}

		$roundedDuration = DurationFormatter::roundTimeInSeconds($duration, 2);

		return DurationFormatter::format($roundedDuration) ?? $fallbackText;
	}

	private function resolveWorkflowStartDurationFromSettings(): ?int
	{
		$settings = $this->getModel()->getSettings();
		$duration = $settings['WORKFLOW_START_DURATION'] ?? null;

		if (is_numeric($duration))
		{
			return max((int)$duration, 0);
		}

		return null;
	}

	public function getMenuItems(): array
	{
		$menuItems = [];
		$workflowId = $this->getModel()->getSettings()['WORKFLOW_ID'] ?? null;

		if (empty($workflowId))
		{
			return [];
		}

		$menuItems['timeline'] = $this->createTimelineMenuItem($workflowId);
		$menuItems['log'] = $this->createLogMenuItem($workflowId)?->setScopeWeb();

		return $menuItems;
	}
}
