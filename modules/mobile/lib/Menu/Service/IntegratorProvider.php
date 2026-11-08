<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\License;
use Bitrix\Main\License\UrlProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Dev\Integrator\IntegratorMock;
use Bitrix\Mobile\Feature\IntegratorFeature;
use Bitrix\Mobile\Feedback\FeedbackFormProvider;
use Bitrix\Mobile\Provider\CommonUserDto;

final class IntegratorProvider
{
	private const CLOUD_DELETE_ACTION = 'bitrix24.v2.Partner.Relation.delete';
	private const BOX_DELETE_ACTION = 'intranet.v2.Partner.Relation.delete';

	/**
	 * Dependencies are explicit: the class must not reach for global state, otherwise it can not be
	 * tested in isolation and a change in main leaks in silently. Defaults keep the call site short,
	 * and they are resolved on first use, so constructing the provider costs nothing by itself.
	 */
	public function __construct(
		private ?UrlProvider $urlProvider = null,
		private ?License $license = null,
	)
	{
	}

	public function getIntegrator(CommonUserDto $user): ?array
	{
		if (Feature::isDisabled(IntegratorFeature::class))
		{
			return null;
		}

		try
		{
			$mock = $this->createDevMock();
			if ($mock !== null)
			{
				return $mock->getIntegrator($user, $this->resolveForms());
			}

			$isCloud = Loader::includeModule('bitrix24');

			if ($isCloud)
			{
				$dto = (new \Bitrix\Bitrix24\Internal\Service\Integrator\IntegratorInfoService())->getIntegratorInfo();
				$cardUrl = $dto->cardUrl;
			}
			else
			{
				Loader::includeModule('intranet');

				$hasLicensePartner = $this->license()->getPartnerId() > 0;

				// The box source refreshes itself over the network on the first read for a portal with a
				// partner, and the parent service gives that request a 25 second socket timeout, while
				// getMenu runs on every app start. Only an admin request may pay for that population.
				if ($this->shouldDeferBoxSource($hasLicensePartner, $this->isBoxSourceStored(), (bool)$user->isAdmin))
				{
					return null;
				}

				$dto = \Bitrix\Intranet\Internal\Integration\Main\Integrator\IntegratorInfoService::createByDefault()
					->getIntegratorInfo()
				;

				// A box source failure throws nothing: the parent returns an empty DTO, so an empty
				// payload next to a license relation means the data did not arrive. Hiding the card
				// beats offering promo to a portal that has an integrator.
				if ($this->isBoxSourceFailure($hasLicensePartner, $dto->toArray()))
				{
					return null;
				}

				// Without a license relation the payload is promo and the url is never read, so the
				// region resolve behind it is skipped as well.
				$cardUrl = $hasLicensePartner ? $this->buildBoxCardUrl((int)$dto->id) : null;
			}

			// Forms are resolved only for the connected branch: on a portal without a partner the
			// promo payload needs none of them, and getMenu is called on every app start.
			if (!$this->hasPartner($dto->toArray()))
			{
				return ['state' => 'promo'];
			}

			[$partnerFormId, $feedbackFormId, $refusalFormId] = $this->resolveForms();

			return $this->normalize(
				$dto,
				$user,
				$cardUrl,
				$partnerFormId,
				$feedbackFormId,
				$refusalFormId,
				$isCloud ? self::CLOUD_DELETE_ACTION : self::BOX_DELETE_ACTION,
			);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * Scenario mocks are a dev/QA tool from mobile/dev and are absent on a real portal:
	 * the class simply does not autoload there.
	 */
	private function createDevMock(): ?IntegratorMock
	{
		return class_exists(IntegratorMock::class) ? IntegratorMock::createIfActive() : null;
	}

	private function normalize(
		Arrayable $dto,
		CommonUserDto $user,
		?string $cardUrl,
		string|int|null $partnerFormId,
		string|int|null $feedbackFormId,
		string|int|null $refusalFormId,
		string $deleteAction,
	): array
	{
		$data = $dto->toArray();

		if (!$this->hasPartner($data))
		{
			return ['state' => 'promo'];
		}

		$canManage = (bool)$user->isAdmin;

		return [
			'state' => 'connected',
			'name' => $data['name'] ?? null,
			'logo' => $data['logo'] ?? null,
			'company' => $data['company'] ?? null,
			'email' => $data['email'] ?? null,
			'phone' => $data['phone'] ?? null,
			'url' => $data['url'] ?? null,
			'cardUrl' => $cardUrl,
			'canManage' => $canManage,
			'partnerFormId' => $partnerFormId,
			'feedbackFormId' => $canManage ? $feedbackFormId : null,
			'refusalFormId' => $canManage ? $refusalFormId : null,
			'deleteAction' => $canManage ? $deleteAction : null,
		];
	}

	private function hasPartner(array $data): bool
	{
		return (int)($data['id'] ?? 0) > 0;
	}

	/**
	 * True when reading the box source would trigger its network refresh and this request must not
	 * pay for it. Without a license relation the source answers from memory and costs nothing.
	 */
	private function shouldDeferBoxSource(bool $hasLicensePartner, bool $isSourceStored, bool $isAdmin): bool
	{
		return $hasLicensePartner && !$isSourceStored && !$isAdmin;
	}

	/**
	 * An empty box payload for a portal whose license points at a partner means the source failed,
	 * not that the partner is gone.
	 */
	private function isBoxSourceFailure(bool $hasLicensePartner, array $data): bool
	{
		return $hasLicensePartner && !$this->hasPartner($data);
	}

	/**
	 * Whether reading the box source stays local. The option belongs to the parent service, which
	 * offers no read-only accessor and treats an empty value as "not fetched yet" - and a failed
	 * fetch stores exactly that, so a failure is never remembered and the next request that may go
	 * to the network goes again.
	 */
	private function isBoxSourceStored(): bool
	{
		return Option::get('bitrix24', 'partner_id', '') !== '';
	}

	private function buildBoxCardUrl(int $id): string
	{
		return $this->formatBoxCardUrl((string)$this->urlProvider()->getPublicDomain(), $id, $this->license()->isCis());
	}

	/**
	 * Trailing slash is normalized on purpose: the caller passes a stringified Uri, which appends it
	 * for an empty path, and this method must not depend on that detail.
	 */
	private function formatBoxCardUrl(string $publicDomain, int $id, bool $isCis): string
	{
		$domain = rtrim($publicDomain, '/');

		return $isCis
			? "{$domain}/partners/partner/{$id}/"
			: "{$domain}/partners/?ID={$id}"
		;
	}

	private function urlProvider(): UrlProvider
	{
		return $this->urlProvider ??= new UrlProvider();
	}

	private function license(): License
	{
		return $this->license ??= new License();
	}

	/**
	 * Form ids are the string top-level keys of the mobile FeedbackFormProvider registry,
	 * which the settings webview resolves to the region-specific {id, sec, zones, lang}.
	 *
	 * @return array{0: ?string, 1: ?string, 2: ?string}
	 */
	private function resolveForms(): array
	{
		return [
			$this->resolveFormKey(FeedbackFormProvider::FORM_INTEGRATOR_REQUEST),
			$this->resolveFormKey(FeedbackFormProvider::FORM_INTEGRATOR_FEEDBACK),
			$this->resolveFormKey(FeedbackFormProvider::FORM_INTEGRATOR_REFUSAL),
		];
	}

	private function resolveFormKey(string $registryKey): ?string
	{
		return $this->formKeyForResolvedForm(FeedbackFormProvider::getFormData($registryKey), $registryKey);
	}

	/**
	 * Returns the registry key when the region has a resolved form, otherwise null so the
	 * client hides the button (soft degradation, e.g. feedback/refusal outside CIS).
	 *
	 * @param array|null $formData resolved region-specific form from FeedbackFormProvider::getFormData()
	 */
	private function formKeyForResolvedForm(?array $formData, string $registryKey): ?string
	{
		return empty($formData) ? null : $registryKey;
	}
}
