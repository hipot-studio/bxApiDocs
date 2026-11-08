<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany;

use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\BitrixAnswersFeature;
use Bitrix\Mobile\Feature\WhatsNewFeature;
use Bitrix\Mobile\Menu\MyCompany\Card\BitrixAnswersCardProvider;
use Bitrix\Mobile\Menu\MyCompany\Card\EmployeesCardProvider;
use Bitrix\Mobile\Menu\MyCompany\Card\PartnersCardProvider;
use Bitrix\Mobile\Menu\MyCompany\Card\PersonalAccountCardProvider;
use Bitrix\Mobile\Menu\MyCompany\Card\SupportCardProvider;
use Bitrix\Mobile\Menu\MyCompany\Dto\BitrixAnswersCardData;
use Bitrix\Mobile\Menu\MyCompany\Dto\EmployeesCardData;
use Bitrix\Mobile\Menu\MyCompany\Dto\SupportCardData;
use Bitrix\Mobile\Menu\Purchases\PurchasesCardProvider;
use Bitrix\Mobile\Menu\Tariff\TariffPlateProvider;
use Bitrix\Mobile\Provider\CommonUserDto;

/**
 * Composition of the "My company" section: one walk over BlockId asks every block once and keeps the
 * ones that answered. A block is shown when it has something to show; the order is BlockId order.
 */
final class BlockProvider
{
	/** @var array<string, ?array>|null block code => data of the block, in display order */
	private ?array $blocks = null;

	private ?PartnersCardProvider $partnersCardProvider = null;

	public function __construct(
		private readonly CommonUserDto $user,
		private readonly MyCompanyProvider $section,
	)
	{
	}

	/**
	 * Codes of the blocks in display order.
	 *
	 * @return list<string> values of BlockId
	 */
	public function getCodes(): array
	{
		return array_keys($this->collect());
	}

	/**
	 * Data of the blocks, keyed by the block code. A block the server gathers nothing for leaves no
	 * record here: the client draws a card per code of the composition and reads its data by that
	 * code.
	 *
	 * @return array<string, array>
	 */
	public function getData(): array
	{
		return array_filter($this->collect(), static fn (?array $data): bool => $data !== null);
	}

	/**
	 * One walk per request: both sides of the answer are read from it, and every block pays for
	 * itself once.
	 *
	 * @return array<string, ?array>
	 */
	private function collect(): array
	{
		if ($this->blocks !== null)
		{
			return $this->blocks;
		}

		$this->blocks = [];

		// The user type gate of the section stays in front of the blocks, whatever their own signals say.
		if (!$this->section->isAvailable())
		{
			return $this->blocks;
		}

		foreach (BlockId::cases() as $blockId)
		{
			$block = $this->resolve($blockId);

			if ($block->isShown)
			{
				$this->blocks[$blockId->value] = $block->data;
			}
		}

		return $this->blocks;
	}

	private function resolve(BlockId $blockId): Block
	{
		return match ($blockId)
		{
			BlockId::Employees => Block::withData($this->getEmployees()),
			BlockId::Tariff => Block::withData($this->getTariff()),
			BlockId::WhatsNew => Block::shownIf(Feature::isEnabled(WhatsNewFeature::class)),
			BlockId::BitrixAnswers => Block::withData($this->getBitrixAnswers()),
			BlockId::Support => Block::withData($this->getSupport()),
			BlockId::PersonalAccount => Block::withData($this->getPersonalAccount()),
			BlockId::Purchases => Block::shownIf(
				(new PurchasesCardProvider())->isVisibleForCurrentUser($this->user),
			),
			BlockId::Integrator => Block::withData($this->getIntegrator()),
			BlockId::ImplementationSpecialists => Block::withData($this->getImplementationSpecialists()),
		};
	}

	private function getEmployees(): ?array
	{
		$card = new EmployeesCardProvider($this->user);
		$company = $card->getCompany();

		if ($company === null)
		{
			return null;
		}

		return EmployeesCardData::make([
			'users' => $company['users'],
			'totalUsersCount' => (int)$company['totalUsersCount'],
			'canInvite' => $this->section->canInvite(),
			'canUseTelephony' => $card->canUseTelephony(),
		])->toArray();
	}

	/**
	 * The visibility flag of the payload is the rule the block is shown by, so an invisible plate
	 * leaves no record rather than a record saying `visible: false`.
	 */
	private function getTariff(): ?array
	{
		$plate = (new TariffPlateProvider())->getData();

		return $plate['visible'] ? $plate : null;
	}

	private function partners(): PartnersCardProvider
	{
		return $this->partnersCardProvider ??= new PartnersCardProvider($this->user);
	}

	private function getIntegrator(): ?array
	{
		return $this->partners()->getIntegrator();
	}

	/**
	 * Needs either something to offer or something to open - an invitation to send or a list with
	 * somebody in it. With neither the block stays out instead of drawing a blank half-row.
	 */
	private function getImplementationSpecialists(): ?array
	{
		$specialists = $this->partners()->getImplementationSpecialists($this->section->canInvite());

		if ($specialists === null)
		{
			return null;
		}

		$hasSomethingToShow = $specialists['canInviteNew']
			|| ($specialists['canView'] && $specialists['totalCount'] > 0)
		;

		return $hasSomethingToShow ? $specialists : null;
	}

	/**
	 * The list of the companies is the availability of the card as well as its data, so an empty list
	 * leaves no record: the flow behind a tap would have nothing to open.
	 */
	private function getPersonalAccount(): ?array
	{
		$data = (new PersonalAccountCardProvider($this->user))->getData();

		return $data['canUsePersonalAccount'] ? ['companies' => $data['companies']] : null;
	}

	/**
	 * The address of the page is the card itself: without it the screen behind a tap would have
	 * nothing to open, so a portal without the ui module gets no block at all.
	 *
	 * Support travels in the same payload because it is opened from inside that screen, on a tap
	 * made when the answer of the section is long spent.
	 */
	private function getBitrixAnswers(): ?array
	{
		if (!$this->hasBitrixAnswers())
		{
			return null;
		}

		$url = (new BitrixAnswersCardProvider())->getUrl();

		if ($url === null)
		{
			return null;
		}

		$support = new SupportCardProvider();
		$botId = $support->getBotId();

		return BitrixAnswersCardData::make([
			'url' => $url,
			'supportBotId' => $botId,
			'helpdeskUrl' => $support->getHelpdeskUrl(),
			'canUseSupport' => $botId > 0,
		])->toArray();
	}

	/**
	 * The support card and the Bitrix Answers card are the same place of the section, so the rolled
	 * out answers take it over entirely.
	 *
	 * The helpdesk is what the card always falls back to, and its address is a per-language constant
	 * of the ui module (\Bitrix\UI\Util::getHelpdeskUrl() picks it by a switch with a default branch
	 * and never answers with an empty string), which leaves the loaded module as the whole condition
	 * of having a card at all.
	 */
	private function getSupport(): ?array
	{
		if ($this->hasBitrixAnswers() || !Loader::includeModule('ui'))
		{
			return null;
		}

		$card = new SupportCardProvider();
		$botId = $card->getBotId();

		return SupportCardData::make([
			'supportBotId' => $botId,
			'helpdeskUrl' => $card->getHelpdeskUrl(),
			// The bot is the first destination and the helpdesk the fallback: the very rule the menu
			// answer carries as restrictions.canUseSupport.
			'canUseSupport' => $botId > 0,
		])->toArray();
	}

	private function hasBitrixAnswers(): bool
	{
		return Feature::isEnabled(BitrixAnswersFeature::class);
	}
}
