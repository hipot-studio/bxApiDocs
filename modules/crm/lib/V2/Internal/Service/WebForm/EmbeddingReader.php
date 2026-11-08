<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\Embedding;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingVariant;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\OpenLineItemCollection;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\SiteButtonItemCollection;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSort;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\OpenLineAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\OpenLinesNotInstalledException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\PhoneNotVerifiedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\SiteButtonAccessDeniedException;
use Bitrix\Crm\V2\Internal\Integration\Bitrix24\PortalRestrictionGateway;
use Bitrix\Crm\V2\Internal\Integration\ImOpenLines\OpenLineGateway;
use Bitrix\Crm\V2\Internal\Repository\WebForm\EmbeddingRepository;
use Bitrix\Crm\V2\Internal\Repository\WebForm\SiteButtonRepository;

/**
 * Reads the ways a CRM form can be embedded - directly into a page, into a site widget or into an
 * open line chat - and owns every check standing in front of that data.
 *
 * The order of the checks is part of the contract: the right to read CRM forms, then the right the
 * particular read stands on, then the restriction the portal lives under, then the existence and
 * admissibility of the form, and only then the data. Nothing is checked once the data is read.
 *
 * The read changes no form data on the portal: form resources are never built here and this code
 * writes no portal setting itself. The core writes one: resolving the address a resource file is
 * served from, it stores the site address in `main/last_site_url` once per process.
 *
 * @see FormDictionaryReader the dictionaries an editor of forms is built on
 *
 * @internal
 */
final class EmbeddingReader
{
	private readonly \Closure $permissionChecker;
	private readonly \Closure $phoneVerificationRequirementChecker;
	private readonly \Closure $phoneVerificationChecker;
	private readonly \Closure $formLinkCodesLoader;
	private readonly \Closure $embeddingLoader;
	private readonly \Closure $publicUrlBuilder;
	private readonly \Closure $previewUrlBuilder;
	private readonly \Closure $siteButtonPermissionChecker;
	private readonly \Closure $siteButtonsLoader;
	private readonly \Closure $activeSiteButtonIdByOpenLineIdLoader;
	private readonly \Closure $siteButtonPreviewUrlBuilder;
	private readonly \Closure $openLineAccessChecker;
	private readonly \Closure $openLinesLoader;
	private readonly \Closure $openLinePreviewUrlBuilder;

	/**
	 * @param null|\Closure(int): bool $permissionChecker
	 * @param null|\Closure(): bool $phoneVerificationRequirementChecker
	 * @param null|\Closure(int): bool $phoneVerificationChecker
	 * @param null|\Closure(int): ?array{code: string, securityCode: string} $formLinkCodesLoader
	 * @param null|\Closure(int): ?Embedding $embeddingLoader
	 * @param null|\Closure(int, string, string): string $publicUrlBuilder
	 * @param null|\Closure(int, string, string, string): string $previewUrlBuilder
	 * @param null|\Closure(int): bool $siteButtonPermissionChecker
	 * @param null|\Closure(int, WebFormSort, int, int): SiteButtonItemCollection $siteButtonsLoader
	 * @param null|\Closure(): array<int, int> $activeSiteButtonIdByOpenLineIdLoader
	 * @param null|\Closure(int, string, string, int): string $siteButtonPreviewUrlBuilder
	 * @param null|\Closure(int): void $openLineAccessChecker
	 * @param null|\Closure(int, int, WebFormSort, int, int): OpenLineItemCollection $openLinesLoader
	 * @param null|\Closure(int, string, string, int): string $openLinePreviewUrlBuilder
	 */
	public function __construct(
		?\Closure $permissionChecker = null,
		?\Closure $phoneVerificationRequirementChecker = null,
		?\Closure $phoneVerificationChecker = null,
		?\Closure $formLinkCodesLoader = null,
		?\Closure $embeddingLoader = null,
		?\Closure $publicUrlBuilder = null,
		?\Closure $previewUrlBuilder = null,
		?\Closure $siteButtonPermissionChecker = null,
		?\Closure $siteButtonsLoader = null,
		?\Closure $activeSiteButtonIdByOpenLineIdLoader = null,
		?\Closure $siteButtonPreviewUrlBuilder = null,
		?\Closure $openLineAccessChecker = null,
		?\Closure $openLinesLoader = null,
		?\Closure $openLinePreviewUrlBuilder = null,
	)
	{
		$this->permissionChecker = $permissionChecker ?? static fn (int $userId): bool =>
			Container::getInstance()
				->getUserPermissions($userId)
				->webForm()
				->canRead()
		;
		$this->phoneVerificationRequirementChecker = $phoneVerificationRequirementChecker
			?? static fn (): bool => (new PortalRestrictionGateway())->isPhoneVerificationRequired()
		;
		$this->phoneVerificationChecker = $phoneVerificationChecker ?? static fn (int $formId): bool =>
			(new PortalRestrictionGateway())->isPhoneVerified($formId)
		;
		$this->formLinkCodesLoader = $formLinkCodesLoader ?? static fn (int $formId): ?array =>
			(new EmbeddingRepository())->getLinkCodesByFormId($formId)
		;
		$this->embeddingLoader = $embeddingLoader ?? static fn (int $formId): ?Embedding =>
			(new EmbeddingRepository())->getByFormId($formId)
		;
		$this->publicUrlBuilder = $publicUrlBuilder ?? static fn (
			int $formId,
			string $formCode,
			string $securityCode,
		): string => (new PublicUrlBuilder())->buildPublicUrl($formId, $formCode, $securityCode);
		$this->previewUrlBuilder = $previewUrlBuilder ?? static fn (
			int $formId,
			string $formCode,
			string $securityCode,
			string $variant,
		): string => (new PublicUrlBuilder())->buildPreviewUrl($formId, $formCode, $securityCode, $variant);
		$this->siteButtonPermissionChecker = $siteButtonPermissionChecker ?? static fn (int $userId): bool =>
			Container::getInstance()
				->getUserPermissions($userId)
				->siteButton()
				->canRead()
		;
		$this->siteButtonsLoader = $siteButtonsLoader ?? static fn (
			int $formId,
			WebFormSort $sort,
			int $limit,
			int $offset,
		): SiteButtonItemCollection => (new SiteButtonRepository())->findAll($formId, $sort, $limit, $offset);
		$this->activeSiteButtonIdByOpenLineIdLoader = $activeSiteButtonIdByOpenLineIdLoader
			?? static fn (): array => (new SiteButtonRepository())->getActiveIdByOpenLineId()
		;
		$this->siteButtonPreviewUrlBuilder = $siteButtonPreviewUrlBuilder ?? static fn (
			int $formId,
			string $formCode,
			string $securityCode,
			int $siteButtonId,
		): string => (new PublicUrlBuilder())
			->buildSiteButtonPreviewUrl($formId, $formCode, $securityCode, $siteButtonId)
		;
		$this->openLineAccessChecker = $openLineAccessChecker ?? static function (int $userId): void {
			(new OpenLineGateway())->assertCanModifyChats($userId);
		};
		$this->openLinesLoader = $openLinesLoader ?? static fn (
			int $userId,
			int $formId,
			WebFormSort $sort,
			int $limit,
			int $offset,
		): OpenLineItemCollection => (new OpenLineGateway())
			->findAll($userId, $formId, $sort, $limit, $offset)
		;
		$this->openLinePreviewUrlBuilder = $openLinePreviewUrlBuilder ?? static fn (
			int $formId,
			string $formCode,
			string $securityCode,
			int $siteButtonId,
		): string => (new PublicUrlBuilder())
			->buildOpenLinePreviewUrl($formId, $formCode, $securityCode, $siteButtonId)
		;
	}

	/**
	 * Returns the direct embedding options of a CRM form together with the links they are shown under.
	 *
	 * An inactive form is a complete answer: every variant comes back as usual and the activity of the
	 * form is a flag of the result, not a reason to refuse.
	 *
	 * @throws FormAccessDeniedException the user may not read CRM forms
	 * @throws PhoneNotVerifiedException the portal demands a verified phone number this form has none of
	 * @throws FormNotFoundException there is no ordinary CRM form under this id
	 */
	public function getEmbedding(int $userId, int $formId): Embedding
	{
		$this->assertCanReadForms($userId);
		$this->assertPhoneVerified($formId);

		$linkCodes = $this->requireFormLinkCodes($formId);

		$embedding = ($this->embeddingLoader)($formId);
		if (!$embedding instanceof Embedding)
		{
			throw new FormNotFoundException();
		}

		return $this->addLinks($embedding, $linkCodes['code'], $linkCodes['securityCode']);
	}

	/**
	 * Returns a page of the site widgets of the portal projected against one CRM form, together with
	 * the preview link of every widget of the page.
	 *
	 * The right to read site widgets is a permission of its own and is asked for before the portal
	 * restriction, unlike legacy: what a user may not do at all is settled before what the portal
	 * demands of a particular form.
	 *
	 * @return array{siteButtons: SiteButtonItemCollection, previewUrlById: array<int, string>}
	 *
	 * @throws FormAccessDeniedException the user may not read CRM forms
	 * @throws SiteButtonAccessDeniedException the user may not read site widgets
	 * @throws PhoneNotVerifiedException the portal demands a verified phone number this form has none of
	 * @throws FormNotFoundException there is no ordinary CRM form under this id
	 */
	public function listSiteButtons(
		int $userId,
		int $formId,
		WebFormSort $sort,
		int $limit,
		int $offset,
	): array
	{
		$this->assertCanReadForms($userId);
		$this->assertCanReadSiteButtons($userId);
		$this->assertPhoneVerified($formId);

		$linkCodes = $this->requireFormLinkCodes($formId);

		$siteButtons = ($this->siteButtonsLoader)($formId, $sort, $limit, $offset);

		$previewUrlById = [];
		foreach ($siteButtons as $siteButton)
		{
			$previewUrlById[$siteButton->id] = ($this->siteButtonPreviewUrlBuilder)(
				$formId,
				$linkCodes['code'],
				$linkCodes['securityCode'],
				$siteButton->id,
			);
		}

		return ['siteButtons' => $siteButtons, 'previewUrlById' => $previewUrlById];
	}

	/**
	 * Returns a page of the open line chats the user may modify, projected against one CRM form,
	 * together with the preview link of every chat that has one.
	 *
	 * @return array{openLines: OpenLineItemCollection, previewUrlById: array<int, string>}
	 *
	 * @throws FormAccessDeniedException the user may not read CRM forms
	 * @throws OpenLinesNotInstalledException the portal has no open lines module
	 * @throws OpenLineAccessDeniedException the user may not modify open line chats
	 * @throws PhoneNotVerifiedException the portal demands a verified phone number this form has none of
	 * @throws FormNotFoundException there is no ordinary CRM form under this id
	 */
	public function listOpenLines(
		int $userId,
		int $formId,
		WebFormSort $sort,
		int $limit,
		int $offset,
	): array
	{
		$this->assertCanReadForms($userId);
		($this->openLineAccessChecker)($userId);
		$this->assertPhoneVerified($formId);

		$linkCodes = $this->requireFormLinkCodes($formId);

		$openLines = ($this->openLinesLoader)($userId, $formId, $sort, $limit, $offset);

		return [
			'openLines' => $openLines,
			'previewUrlById' => $this->buildOpenLinePreviewUrls($openLines, $formId, $linkCodes),
		];
	}

	/**
	 * A chat is previewed inside the site widget showing it, so a chat no active widget shows stays
	 * out of the map and its preview link is the absence of one.
	 *
	 * @param array{code: string, securityCode: string} $linkCodes
	 * @return array<int, string>
	 */
	private function buildOpenLinePreviewUrls(
		OpenLineItemCollection $openLines,
		int $formId,
		array $linkCodes,
	): array
	{
		if ($openLines->isEmpty())
		{
			return [];
		}

		$siteButtonIdByOpenLineId = ($this->activeSiteButtonIdByOpenLineIdLoader)();

		$previewUrlById = [];
		foreach ($openLines as $openLine)
		{
			$siteButtonId = $siteButtonIdByOpenLineId[$openLine->id] ?? null;
			if ($siteButtonId === null)
			{
				continue;
			}

			$previewUrlById[$openLine->id] = ($this->openLinePreviewUrlBuilder)(
				$formId,
				$linkCodes['code'],
				$linkCodes['securityCode'],
				$siteButtonId,
			);
		}

		return $previewUrlById;
	}

	private function assertCanReadSiteButtons(int $userId): void
	{
		if (!(bool)($this->siteButtonPermissionChecker)($userId))
		{
			throw new SiteButtonAccessDeniedException();
		}
	}

	private function assertCanReadForms(int $userId): void
	{
		if (!(bool)($this->permissionChecker)($userId))
		{
			throw new FormAccessDeniedException();
		}
	}

	/**
	 * The gateway only reports what the portal demands; refusing the read is the decision of this
	 * scenario. A portal that demands nothing is never asked about a particular form.
	 */
	private function assertPhoneVerified(int $formId): void
	{
		if (!(bool)($this->phoneVerificationRequirementChecker)())
		{
			return;
		}

		if (!(bool)($this->phoneVerificationChecker)($formId))
		{
			throw new PhoneNotVerifiedException();
		}
	}

	/**
	 * A form of another kind is as absent here as a form that never existed: both leave the caller
	 * with the same answer and tell it nothing about which of the two it hit.
	 *
	 * @return array{code: string, securityCode: string}
	 */
	private function requireFormLinkCodes(int $formId): array
	{
		$linkCodes = ($this->formLinkCodesLoader)($formId);
		if (!is_array($linkCodes))
		{
			throw new FormNotFoundException();
		}

		return $linkCodes;
	}

	private function addLinks(Embedding $embedding, string $formCode, string $securityCode): Embedding
	{
		$formId = $embedding->formId;

		return new Embedding(
			formId: $formId,
			formActive: $embedding->formActive,
			resourcesReady: $embedding->resourcesReady,
			inline: $this->addPreviewUrl($embedding->inline, $formId, $formCode, $securityCode),
			click: $this->addPreviewUrl($embedding->click, $formId, $formCode, $securityCode),
			auto: $this->addPreviewUrl($embedding->auto, $formId, $formCode, $securityCode),
			publicUrl: ($this->publicUrlBuilder)($formId, $formCode, $securityCode),
		);
	}

	/**
	 * A preview link stands on the public path of the form alone, so it is there for every variant
	 * even while the resources are missing and the embed code of the variant is empty.
	 */
	private function addPreviewUrl(
		EmbeddingVariant $variant,
		int $formId,
		string $formCode,
		string $securityCode,
	): EmbeddingVariant
	{
		return new EmbeddingVariant(
			name: $variant->name,
			code: $variant->code,
			previewUrl: ($this->previewUrlBuilder)($formId, $formCode, $securityCode, $variant->name),
			settings: $variant->settings,
			options: $variant->options,
		);
	}
}
