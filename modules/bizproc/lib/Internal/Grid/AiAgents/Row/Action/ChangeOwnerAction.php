<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Action;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsActionType;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Settings\AiAgentsSettings;
use Bitrix\Bizproc\Public\Provider\WorkflowTemplate\AiAgentProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CBPWorkflowTemplateUser;

/**
 * Hands a launched AI-agent copy over to another employee.
 *
 * Two kinds of unavailability, deliberately different. A row the transfer makes no sense for at all
 * (a system template of the catalogue, a copy that was never started) carries no item. A row the
 * current user simply may not hand over carries the item as a disabled stub with an explanation.
 *
 * The right itself is never recomputed here: AiAgentProvider::canTransferOwnership stays the single
 * source of truth shared with the changeOwner action of the controller.
 */
class ChangeOwnerAction extends JsGridAction
{
	private const TEST_ATTRIBUTE = 'data-test-id';
	private const TEST_ID = 'bizproc-ai-agents-grid-action-change-owner';

	private readonly AiAgentProvider $aiAgentProvider;

	/** @var array<int, bool> */
	private array $transferRights = [];

	public function __construct(AiAgentsSettings $settings, ?AiAgentProvider $aiAgentProvider = null)
	{
		parent::__construct($settings);

		$this->aiAgentProvider = $aiAgentProvider ?? ServiceLocator::getInstance()->get(AiAgentProvider::class);
	}

	public static function getId(): string
	{
		return AiAgentsActionType::CHANGE_OWNER->value;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BIZPROC_AI_AGENTS_GRID_ACTION_CHANGE_OWNER') ?? '';
	}

	/**
	 * @param array{ID: string, SYSTEM_CODE?: ?string, ACTIVATED_BY?: string, ACTIVATED_AT?: string} $rawFields
	 */
	public function getControl(array $rawFields): ?array
	{
		if (!$this->isTransferableRow($rawFields))
		{
			return null;
		}

		$control = parent::getControl($rawFields);

		if (!isset($control))
		{
			return null;
		}

		// The property map of the base class knows no test markers, but the control array reaches the
		// menu item of main.popup as is, and its attrs are applied to the item node itself - so the
		// anchor of the item is set from here, in the attribute the grid extension already uses.
		//
		// Written out as an attribute rather than through the dataset of the item on purpose: the row
		// the transfer re-reads goes through the camel-case converter of the ajax answer, and it would
		// fold a dataset key into one word, leaving the repainted item with a different attribute than
		// the server-rendered one.
		$control['attrs'] = [self::TEST_ATTRIBUTE => self::TEST_ID];

		if (!$this->isEnabled($rawFields))
		{
			// The base class explains a missing right in terms of editing, the transfer needs its own text.
			$control['title'] = $this->getDisabledText();

			// The base class only greys the item out with a class. The menu item of main.popup carries
			// the state programmatically only when it is told about it, so without this a screen reader
			// reports the stub as an ordinary item that simply does nothing when activated.
			$control['disabled'] = true;
		}

		return $control;
	}

	protected function isEnabled(array $rawFields): bool
	{
		if (!$this->isTransferableRow($rawFields))
		{
			return false;
		}

		return $this->canTransferOwnership($this->extractTemplateId($rawFields));
	}

	protected function getActionParams(array $rawFields): array
	{
		return [
			'templateId' => $this->extractTemplateId($rawFields),
			'expectedOwnerId' => $this->extractOwnerId($rawFields),
		];
	}

	protected function getCurrentUserId(): int
	{
		return (int)(new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser))->getId();
	}

	private function isTransferableRow(array $rawFields): bool
	{
		return $this->extractTemplateId($rawFields) > 0
			&& !$this->isSystemTemplate($rawFields)
			&& !empty($rawFields['ACTIVATED_AT'])
		;
	}

	/**
	 * Memoized per template: the control of a row asks for the right twice, once to render and once to
	 * choose the explanation, and the predicate goes to the database.
	 */
	private function canTransferOwnership(int $templateId): bool
	{
		return $this->transferRights[$templateId] ??= $this->aiAgentProvider->canTransferOwnership(
			$templateId,
			$this->getCurrentUserId(),
			$this->isUserAdmin(),
		);
	}

	private function getDisabledText(): string
	{
		return Loc::getMessage('BIZPROC_AI_AGENTS_GRID_ACTION_CHANGE_OWNER_NO_ACCESS') ?? '';
	}

	private function extractTemplateId(array $rawFields): int
	{
		return $this->extractId($rawFields['ID'] ?? '');
	}

	private function extractOwnerId(array $rawFields): int
	{
		return $this->extractId($rawFields['ACTIVATED_BY'] ?? '');
	}

	private function extractId(int|string|null $value): int
	{
		$id = filter_var($value, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
			],
		]);

		return $id ?: 0;
	}
}
