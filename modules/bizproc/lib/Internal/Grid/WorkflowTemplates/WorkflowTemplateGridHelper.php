<?php

namespace Bitrix\Bizproc\Internal\Grid\WorkflowTemplates;

use Bitrix\Bizproc\UI\UserView;
use Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate;
use Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection;
use Bitrix\Main\EO_User;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;
use Bitrix\UI\Buttons\AirButtonStyle;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Color;
use Bitrix\UI\Buttons\LinkTarget;
use Bitrix\UI\Buttons\Size;
use CBPViewHelper;

final class WorkflowTemplateGridHelper
{
	public function createGridData(EO_WorkflowTemplate_Collection $collection): array
	{
		$data = [];

		foreach ($collection as $template)
		{
			/** @var EO_User $editor */
			$editor = $template->getUpdatedUser() ?? null;
			/** @var EO_User $creator */
			$creator = $template->getCreatedUser() ?? $template->getUser();
			/** @var EO_WorkflowTemplate $template */
			$data[] = [
				'ID' => $template->getId(),
				'NAME' => $this->createNameCell($template),
				'ACTIONS' => $this->createActionCell($template),
				'MODIFIED' => CBPViewHelper::formatDateTime($template->getModified()),
				'EDITOR' => $editor != null ? $this->createUserCell($editor) : null,
				'CREATOR' => $creator != null ? $this->createUserCell($creator) : null,
			];
		}

		return $data;
	}

	private function createNameCell(EO_WorkflowTemplate $template): array
	{
		return [
			'templateId' => $template->getId(),
			'name' => $template->getName(),
			'description' => $template->getDescription(),
		];
	}

	private function createActionCell(EO_WorkflowTemplate $template): string
	{
		$actionButton = new Button([
			'id' => 'add_new_workflow_button',
			'color' => Color::PRIMARY,
			'size' => Size::SMALL,
			'className' => 'ui-text-underline-none',
			'text' => Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_CHANGE_BUTTON'),
			'link' => "/bizprocdesigner/editor/?ID={$template->getId()}",
			'air' => true,
			'style' => AirButtonStyle::FILLED,
			'target' => LinkTarget::LINK_TARGET_BLANK,
		]);

		return $actionButton->render();
	}

	private function createUserCell(EO_User $user): array
	{
		$userView = new UserView($user);
		$avatar = $userView->getUserAvatar();
		$emptyAvatar = (empty($avatar) ? 'empty' : '');
		$avatarStyle = (empty($avatar) ? '' : ' style="background-image: url(\'' . Uri::urnEncode($avatar) . '\')"');
		$fullName = $userView->getFullName();

		return [
			'visible' => $emptyAvatar,
			'style' => $avatarStyle,
			'userId' => $userView->getUserId(),
			'fullName' => $fullName,
		];
	}
}