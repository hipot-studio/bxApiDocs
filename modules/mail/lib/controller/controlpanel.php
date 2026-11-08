<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Intranet;
use Bitrix\Mail\Access\Model\UserModel;
use Bitrix\Main;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\MailService\Internal\Service\Sso\AdminEntryScenario;
use Bitrix\MailService\Public\Service\Sso\MailhostAdminEntryService;

Loc::loadMessages(__FILE__);

/**
 * The way into the control panel of the company mail. Scope: api.
 *
 *   getEntryUrl POST {} -> {url}
 *
 * The address is asked for at the moment a person presses a button and never before: the service issues a
 * one-off ticket that lives 60 seconds and dies on the first use, so an address stored anywhere - in an
 * option, in a decision of the server, in the markup of a page - would be dead by the time it was followed.
 * That is why this is an action and not a field of a page.
 *
 * The panel is for administrators of the portal. The service reads no model of rights of Bitrix24 and is
 * promised an administrator, so the promise is kept here: a person who is not one is refused on this side
 * rather than sent to a refusal at the far end.
 *
 * The service lives in a module of its own, released apart from this one. A portal without it answers "the
 * panel is not to be had" instead of breaking: the button is one feature of a page, and the page must render
 * without it.
 */
final class ControlPanel extends Controller
{
	public const ERROR_ACCESS_DENIED = 'access_denied';
	public const ERROR_ENTRY_UNAVAILABLE = 'entry_unavailable';

	private const SERVICE_MODULE_ID = 'mailservice';

	protected function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST]),
			new Main\Engine\ActionFilter\Csrf(),
			// The address is fetched over the network, so the action holds no lock of the session
			new Main\Engine\ActionFilter\CloseSession(),
			new Intranet\ActionFilter\IntranetUser(),
		];
	}

	/**
	 * The address this person is sent to, or a refusal naming why there is none.
	 *
	 * Nothing of the answer is kept and nothing is logged: the address carries a secret in its fragment, and
	 * the fragment is the reason the whole thing goes back to the browser untouched.
	 */
	public function getEntryUrlAction(): ?array
	{
		$userId = $this->getUserId();

		if ($userId <= 0 || !UserModel::createFromId($userId)->isAdmin())
		{
			$this->addFailure(403, self::ERROR_ACCESS_DENIED);

			return null;
		}

		$entry = $this->resolveEntryService();
		if ($entry === null)
		{
			$this->addFailure(503, self::ERROR_ENTRY_UNAVAILABLE);

			return null;
		}

		$issued = $entry->issueFor($userId, $this->displayNameOf($userId));
		if (!$issued->isSuccess())
		{
			/*
				The reason the service refused is not passed on as it came: its codes name the states of a
				contract with a neighbouring service, and a client of this action has one thing to do with a
				refusal - say the panel cannot be opened right now.
			*/
			$this->addFailure(503, self::ERROR_ENTRY_UNAVAILABLE);

			return null;
		}

		return ['url' => (string)$issued->getData()[AdminEntryScenario::URL_DATA_KEY]];
	}

	/** The service of the entry, or nothing at all on a portal this module is not deployed with. */
	private function resolveEntryService(): ?MailhostAdminEntryService
	{
		if (!Loader::includeModule(self::SERVICE_MODULE_ID))
		{
			return null;
		}

		if (!class_exists(MailhostAdminEntryService::class))
		{
			return null;
		}

		return new MailhostAdminEntryService();
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	/** What the administration shows the entered person as. A person without a name is entered without one. */
	private function displayNameOf(int $userId): string
	{
		$user = Main\UserTable::getRow([
			'select' => ['NAME', 'LAST_NAME', 'LOGIN'],
			'filter' => ['=ID' => $userId],
		]);

		if ($user === null)
		{
			return '';
		}

		$name = trim(implode(' ', array_filter([
			(string)($user['NAME'] ?? ''),
			(string)($user['LAST_NAME'] ?? ''),
		])));

		return $name === '' ? trim((string)($user['LOGIN'] ?? '')) : $name;
	}

	private function addFailure(int $status, string $code): void
	{
		Main\Context::getCurrent()?->getResponse()->setStatus((string)$status);
		$this->addError(new Error($this->messageOf($code), $code));
	}

	private function messageOf(string $code): string
	{
		return (string)Loc::getMessage('MAIL_CONTROL_PANEL_ERROR_' . mb_strtoupper($code));
	}
}
