<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Stub;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionListQuery;
use Bitrix\UI\Buttons\AirButtonStyle;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Size;
use Closure;

final class StubService
{
	private const STATE_PRISTINE = 'pristine';
	private const STATE_SEARCH = 'search';
	private const STATE_FILTERED = 'filtered';

	/**
	 * @param Closure(): bool $rulesExist called only when it decides between pristine and filtered
	 */
	public function resolve(Closure $rulesExist, SectionListQuery $query, string $gridId): string
	{
		$search = $query->search;
		$hasFieldFilter = $query->roleFilter !== null || $query->hasNarrowingFilter();
		if ($search !== null && !$hasFieldFilter && $query->replicateActive === true)
		{
			return $this->renderSearch($search, $gridId);
		}

		// An empty filter has nothing to reset, so the absence of rules under it is the
		// onboarding state - both on the pinned "active recurrence" preset the section
		// opens with and on the filter the user cleared down to all statuses.
		$isFilterEmpty = $search === null && !$hasFieldFilter && $query->replicateActive !== false;
		if ($isFilterEmpty && !$rulesExist())
		{
			return $this->renderPristine($gridId);
		}

		return $this->renderFiltered($gridId);
	}

	private function renderPristine(string $gridId): string
	{
		return $this->render(
			state: self::STATE_PRISTINE,
			gridId: $gridId,
			illustration: '<img class="tasks-replication-grid-stub__illustration" src="/bitrix/images/tasks/recurring-empty.webp" alt="" aria-hidden="true">',
			title: (string)Loc::getMessage('TASKS_V2_REPLICATION_GRID_STUB_PRISTINE_TITLE'),
			description: (string)Loc::getMessage('TASKS_V2_REPLICATION_GRID_STUB_PRISTINE_DESCRIPTION'),
			buttons: [],
		);
	}

	private function renderSearch(string $search, string $gridId): string
	{
		// The STUB reaches the output as it is written here: the component escapes the param
		// with htmlspecialcharsEx() and the grid template reverses it with htmlspecialcharsback().
		$escapedSearch = htmlspecialcharsbx($search);
		$description = Loc::getMessage(
			'TASKS_V2_REPLICATION_GRID_STUB_SEARCH_DESCRIPTION',
			['#SEARCH#' => $escapedSearch],
		) ?? $escapedSearch;

		return $this->render(
			state: self::STATE_SEARCH,
			gridId: $gridId,
			illustration: $this->renderEmptySearchIllustration(),
			title: (string)Loc::getMessage('TASKS_V2_REPLICATION_GRID_STUB_SEARCH_TITLE'),
			description: $description,
			buttons: [
				$this->renderButton(
					action: 'reset-search',
					text: (string)Loc::getMessage('TASKS_V2_REPLICATION_GRID_STUB_RESET_SEARCH'),
					style: AirButtonStyle::OUTLINE_NO_ACCENT,
				),
			],
		);
	}

	private function renderFiltered(string $gridId): string
	{
		$buttons = [
			$this->renderButton(
				action: 'reset-filter',
				text: (string)Loc::getMessage('TASKS_V2_REPLICATION_GRID_STUB_RESET_FILTER'),
				style: AirButtonStyle::OUTLINE_NO_ACCENT,
			),
		];

		return $this->render(
			state: self::STATE_FILTERED,
			gridId: $gridId,
			illustration: $this->renderEmptySearchIllustration(),
			title: (string)Loc::getMessage('TASKS_V2_REPLICATION_GRID_STUB_FILTERED_TITLE'),
			description: (string)Loc::getMessage('TASKS_V2_REPLICATION_GRID_STUB_FILTERED_DESCRIPTION'),
			buttons: $buttons,
		);
	}

	private function renderEmptySearchIllustration(): string
	{
		return '<div class="tasks-replication-grid-stub__illustration" data-illustration="ui.system.illustration" data-illustration-name="empty-search" aria-hidden="true"></div>';
	}

	private function renderButton(string $action, string $text, string $style): string
	{
		$button = new Button([
			'text' => $text,
			'air' => true,
			'style' => $style,
			'size' => Size::MEDIUM,
			'dataset' => [
				'testid' => 'tasks-replication-grid-stub-' . $action,
				'action' => $action,
			],
		]);
		$button->addAttribute('type', 'button');

		return $button->render(false);
	}

	private function render(
		string $state,
		string $gridId,
		string $illustration,
		string $title,
		string $description,
		array $buttons,
	): string
	{
		// ui.system.typography declares the --ui-headline-*/--ui-text-* tokens on these very
		// classes, so the markup cannot drop them: the section styles only restate the same
		// tokens and would resolve to nothing without the class that defines them.
		$titleSize = $state === self::STATE_PRISTINE ? '--lg' : '--md';
		$actions = $buttons === []
			? ''
			: '<div class="tasks-replication-grid-stub__actions">' . implode('', $buttons) . '</div>'
		;

		return '<div class="tasks-replication-grid-stub" data-testid="tasks-replication-grid-stub-' . $state
			. '" data-role="tasks-replication-grid-stub" data-state="'
			. $state
			. '" data-grid-id="'
			. htmlspecialcharsbx($gridId)
			. '">'
			. $illustration
			. '<div class="tasks-replication-grid-stub__content">'
			. '<h2 class="tasks-replication-grid-stub__title ui-headline ' . $titleSize . '">' . $title . '</h2>'
			. '<p class="tasks-replication-grid-stub__description ui-text --md">' . $description . '</p>'
			. $actions
			. '</div>'
			. '</div>'
		;
	}
}
