<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany\Card;

use Bitrix\Intranet\Invitation;
use Bitrix\Intranet\Public\Service\IntegratorService;
use Bitrix\IntranetMobile\Provider\ImplementationSpecialistProvider;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Dev\MyCompany\ImplementationSpecialistsSandbox;
use Bitrix\Mobile\Feature\ImplementationSpecialistsFeature;
use Bitrix\Mobile\Menu\Service\IntegratorProvider;
use Bitrix\Mobile\Provider\CommonUserDto;

/**
 * Data of the partners card: the integrator of the portal and the implementation specialists it
 * has invited. Two tiles of one row, each with a rollout gate of its own, so either of them may
 * answer with nothing while the other has something to show.
 */
final class PartnersCardProvider
{
	public function __construct(private readonly CommonUserDto $user)
	{
	}

	/**
	 * Specialists tile: counters, permissions and the avatar preview. The per-user companion of the
	 * list is not built here - it is fetched when the list opens.
	 *
	 * @param bool|null $canInvite the invite permission of the section, read here when the caller
	 *        has not read it already
	 */
	public function getImplementationSpecialists(?bool $canInvite = null): ?array
	{
		if (!$this->passesSpecialistsGate())
		{
			return null;
		}

		$canView = CurrentUser::get()->canDoOperation('edit_all_users');
		$canInvite ??= Invitation::canCurrentUserInvite();

		if (!$canView && !$canInvite)
		{
			return null;
		}

		// The dev sandbox substitutes the payload of the tile, never a gate in front of it.
		$sandbox = self::createDevSandbox($this->user->id);
		if ($sandbox !== null)
		{
			return $sandbox->getSection();
		}

		$provider = new ImplementationSpecialistProvider();

		$data = $canView
			? $provider->getSpecialists()
			: [
				'users' => [],
				'totalCount' => 0,
				'canInviteMore' => $provider->canInviteMore(),
			];

		return [
			'canView' => $canView,
			'canInvite' => $canInvite,
			'canInviteMore' => $data['canInviteMore'],
			'canInviteNew' => $canInvite
				&& $data['canInviteMore']
				&& Invitation::isNewInvitationAvailable(),
			'isRenamed' => IntegratorService::createByDefault()->isRenamedIntegrator(),
			'totalCount' => $data['totalCount'],
			'users' => $data['users'],
		];
	}

	/**
	 * The sandbox is a dev/QA tool from mobile/dev and is absent on a real portal: the class simply
	 * does not autoload there.
	 */
	public static function createDevSandbox(int $userId): ?ImplementationSpecialistsSandbox
	{
		return class_exists(ImplementationSpecialistsSandbox::class)
			? ImplementationSpecialistsSandbox::createIfEnabled($userId)
			: null
		;
	}

	/**
	 * Rollout gate of the specialists: the feature flag, the user type and the modules the tile is
	 * built of. The lazy list of the specialists is served by the same gate, so it stays public.
	 */
	public function passesSpecialistsGate(): bool
	{
		if (Feature::isDisabled(ImplementationSpecialistsFeature::class))
		{
			return false;
		}

		if ($this->user->isExtranet || $this->user->isCollaber || $this->user->isGuest)
		{
			return false;
		}

		return Loader::includeModule('intranet')
			&& Loader::includeModule('intranetmobile')
			&& Loader::includeModule('bitrix24')
		;
	}

	/**
	 * Integrator tile: the partner of the portal when there is one and the promo state otherwise.
	 *
	 * The two signals of the visible view travel with the payload wherever it goes, so the card
	 * draws itself without the contacts and the form ids behind them.
	 */
	public function getIntegrator(): ?array
	{
		if ($this->user->isExtranet || $this->user->isCollaber || $this->user->isGuest)
		{
			return null;
		}

		$integrator = (new IntegratorProvider())->getIntegrator($this->user);

		if ($integrator === null)
		{
			return null;
		}

		return array_merge($integrator, [
			'canContact' => IntegratorViewSignals::canContact($integrator),
			'hasMenuActions' => IntegratorViewSignals::hasMenuActions($integrator),
		]);
	}
}
