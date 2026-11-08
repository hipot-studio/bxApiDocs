<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Main\ModuleManager;

/**
 * Row menu items in the shape the screen has always given them: the same order, ids and handler
 * strings, assembled here with their escaping - besides the binding placeholders, the one place
 * where the server still writes markup for the client.
 */
class RowActionsBuilder
{
	private const DELETE_IMMEDIATELY_LOCKED_DIR = '[Gmail]/All Mail';

	private readonly array $actions;

	public function __construct(
		private readonly ScreenContext $context,
		private readonly string $currentDir,
	)
	{
		$this->actions = GridActionsCatalog::getAll();
	}

	/**
	 * @param array<string, scalar> $taskParams What a new task out of this message is opened with
	 * @param array<string, scalar> $postParams What a new post out of this message is opened with
	 */
	public function build(string $rowId, int $messageId, array $taskParams, array $postParams): array
	{
		return [
			...$this->buildReadActions($rowId),
			...$this->buildMoveAction($rowId),
			...$this->buildLabelAction($rowId),
			...$this->buildSpamActions($rowId),
			$this->buildDeleteAction($rowId),
			[
				'id' => 'separator',
				'additionalClassForPanel' => 'mail-separator',
				'hideInActionPanel' => true,
			],
			...$this->buildCrmActions($rowId),
			$this->buildTaskAction($taskParams),
			$this->buildDiscussAction($messageId, $postParams),
			$this->buildEventAction($messageId),
			$this->buildDeleteImmediatelyAction($rowId),
		];
	}

	/** Both items are always present; the client hides the one the message state does not call for. */
	private function buildReadActions(string $rowId): array
	{
		$onclick = $this->callManager("onReadClick('{$rowId}')");

		return [
			$this->buildToggleAction('notRead', 'not-read-action', $onclick),
			$this->buildToggleAction('read', 'read-action', $onclick),
		];
	}

	private function buildSpamActions(string $rowId): array
	{
		$onclick = $this->callManager("onSpamClick('{$rowId}')");

		return [
			$this->buildToggleAction('notSpam', 'not-spam-action', $onclick, withNoIconClass: false),
			$this->buildToggleAction('spam', 'spam-action', $onclick, withNoIconClass: false),
		];
	}

	private function buildToggleAction(
		string $key,
		string $role,
		string $onclick,
		bool $withNoIconClass = true,
	): array
	{
		$action = $this->actions[$key];
		$label = '<span data-role="' . $role . '">' . $action['text'] . '</span>';

		$item = [
			'id' => $action['id'],
			'icon' => $action['icon'],
			'html' => $label,
			'text' => $label,
			'title' => $action['title'],
			// read and spam change the message on the server, so a mailbox being moved refuses them;
			// the client reads this mark on the row and on the panel alike
			'dataset' => ['migrationDangerous' => true],
			'onclick' => $onclick,
		];

		if ($withNoIconClass)
		{
			$item['className'] = 'menu-popup-no-icon';
		}

		return $item;
	}

	/**
	 * The folder tree is one per mailbox, not one per row, so the submenu travels empty and the client
	 * hangs its own tree on it.
	 */
	private function buildMoveAction(string $rowId): array
	{
		if ($this->context->isAllMailMode)
		{
			return [];
		}

		$action = $this->actions['move'];

		return [[
			'id' => $action['id'] . $rowId,
			'icon' => $action['icon'],
			'text' => $action['text'],
			'title' => $action['title'],
			'dataset' => ['migrationDangerous' => true],
			'items' => $this->context->directoryMenuItems,
			'gridRowId' => $rowId,
		]];
	}

	private function buildLabelAction(string $rowId): array
	{
		if (!$this->context->labelsEnabled)
		{
			return [];
		}

		$action = $this->actions['assignLabel'];

		return [[
			'id' => $action['id'] . $rowId,
			'icon' => $action['icon'],
			'text' => $action['text'],
			'title' => $action['title'],
			'gridRowId' => $rowId,
			'dataset' => ['labelMenu' => true],
			'items' => [GridActionsCatalog::getLoadingItem()],
		]];
	}

	private function buildDeleteAction(string $rowId): array
	{
		$action = $this->actions['delete'];

		return [
			'id' => $action['id'],
			'icon' => $action['icon'],
			'text' => $action['text'],
			'title' => $action['title'],
			'dataset' => ['migrationDangerous' => true],
			'onclick' => $this->callManager("onDeleteClick('{$rowId}')"),
		];
	}

	private function buildCrmActions(string $rowId): array
	{
		$onclick = $this->resolveCrmOnclick($rowId);

		return [
			$this->buildCrmAction('addToCrm', 'crm-action', 'mail-crm-action', $onclick),
			$this->buildCrmAction('excludeFromCrm', 'not-crm-action', 'mail-not-crm-action', $onclick),
		];
	}

	private function buildCrmAction(string $key, string $role, string $panelClass, string $onclick): array
	{
		$action = $this->actions[$key];
		$label = '<span data-role="' . $role . '">' . $action['text'] . '</span>';

		return [
			'id' => $action['id'],
			'html' => $label,
			'text' => $label,
			'title' => $action['title'],
			'onclick' => $onclick,
			'additionalClassForPanel' => $panelClass,
			'hideInActionPanel' => true,
		];
	}

	private function resolveCrmOnclick(string $rowId): string
	{
		if (!ModuleManager::isModuleInstalled('crm'))
		{
			return "BX.Mail.Client.Item.showError('crm-install-error');";
		}

		if (!$this->context->crmActivityPermission)
		{
			return "BX.Mail.Client.Item.showError('crm-install-permission-working-error');";
		}

		return $this->callManager("onCrmClick('{$rowId}')");
	}

	private function buildTaskAction(array $taskParams): array
	{
		$action = $this->actions['task'];
		$installed = ModuleManager::isModuleInstalled('tasks');

		return [
			'id' => $action['id'],
			'text' => $action['text'],
			'title' => $action['title'],
			...$this->addressOfTheItem(RowHrefTemplates::TASK_CREATE, $taskParams, $installed),
			'onclick' => $installed
				? $this->openSlider("'cacheable': false, 'loader': 'task-new-loader'")
				: "BX.Mail.Client.Item.showError('tasks-install-error');",
			'dataset' => ['sliderIgnoreAutobinding' => true],
			'additionalClassForPanel' => 'mail-task',
			'hideInActionPanel' => true,
		];
	}

	private function buildDiscussAction(int $messageId, array $postParams): array
	{
		$action = $this->actions['discuss'];
		$chatInstalled = ModuleManager::isModuleInstalled('im');
		$feedInstalled = ModuleManager::isModuleInstalled('socialnetwork');

		return [
			'id' => $action['id'],
			'text' => $action['text'],
			'title' => $action['title'],
			'additionalClassForPanel' => 'mail-discuss',
			'hideInActionPanel' => true,
			'items' => [
				[
					'id' => $this->actions['discussInChat']['id'],
					'text' => $this->actions['discussInChat']['text'],
					'title' => $this->actions['discussInChat']['title'],
					'onclick' => $chatInstalled
						? 'BX.Mail.Client.Action.DiscussInChat.open(' . $messageId . ')'
						: "BX.Mail.Client.Item.showError('chat-install-error');",
				],
				[
					'id' => $this->actions['chat']['id'],
					'text' => $this->actions['chat']['text'],
					'title' => $this->actions['chat']['title'],
					'onclick' => $chatInstalled
						? 'BX.Mail.Secretary.getInstance(' . $messageId . ').openChat()'
						: "BX.Mail.Client.Item.showError('chat-install-error');",
				],
				[
					'id' => $this->actions['liveFeed']['id'],
					'text' => $this->actions['liveFeed']['text'],
					'title' => $this->actions['liveFeed']['title'],
					...$this->addressOfTheItem(RowHrefTemplates::POST_CREATE, $postParams, $feedInstalled),
					'onclick' => $feedInstalled
						? $this->openSlider("'cacheable': false, 'loader': 'socialnetwork:userblogposteditex'")
						: "BX.Mail.Client.Item.showError('socialnetwork-install-error');",
					'dataset' => ['sliderIgnoreAutobinding' => true],
				],
			],
		];
	}

	private function buildEventAction(int $messageId): array
	{
		$action = $this->actions['event'];

		return [
			'id' => $action['id'],
			'text' => $action['text'],
			'title' => $action['title'],
			'additionalClassForPanel' => 'mail-meeting',
			'hideInActionPanel' => true,
			'onclick' => ModuleManager::isModuleInstalled('calendar')
				? 'BX.Mail.Secretary.getInstance(' . $messageId . ').openCalendarEvent()'
				: "BX.Mail.Client.Item.showError('calendar-install-error');",
		];
	}

	private function buildDeleteImmediatelyAction(string $rowId): array
	{
		$action = $this->actions['deleteImmediately'];

		return [
			'id' => $action['id'],
			'text' => $action['text'],
			'title' => $action['title'],
			'disabled' => $this->currentDir === self::DELETE_IMMEDIATELY_LOCKED_DIR,
			'dataset' => ['migrationDangerous' => true],
			'onclick' => $this->callManager("onDeleteImmediately('{$rowId}')"),
			'hiddenInPanel' => true,
		];
	}

	private function callManager(string $call): string
	{
		return "BX.Mail.Client.Message.List['"
			. \CUtil::jsEscape(ScreenContext::LIST_MANAGER_ID)
			. "']." . $call . ';'
		;
	}

	/**
	 * Where the item leads, for the two items that lead outside the mail section. The page names the
	 * template of the screen and the parameters of this row, and the client puts the address together
	 * - a page built by the controller has no access to those settings (RowHrefTemplates).
	 *
	 * @return array<string, mixed>
	 */
	private function addressOfTheItem(string $template, array $params, bool $installed): array
	{
		return $installed
			? ['hrefTemplate' => $template, 'hrefParams' => $params]
			: ['href' => '']
		;
	}

	/**
	 * The address is read off the node at the moment of the press instead of being written into the
	 * handler: the handler is assembled here with its escaping, while the address of the item is not
	 * known to the server at all.
	 */
	private function openSlider(string $options): string
	{
		return "top.BX.SidePanel.Instance.open(this.getAttribute('href'), {"
			. $options
			. "}); if (event = event || window.event) event.preventDefault(); "
		;
	}
}
