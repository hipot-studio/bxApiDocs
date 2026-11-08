<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\PhoneVerify;
use Bitrix\Crm\WebForm\Form;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;

/**
 * The single point CRM V2 asks the cloud platform about the restrictions a portal lives under.
 *
 * The platform module is an optional dependency: a self-hosted portal has none of it, and every
 * answer here then means "no restriction applies" - the same conclusion legacy reaches by
 * short-circuiting on the loader.
 *
 * The gateway answers questions and never decides on access: turning "a verified phone is required
 * and this form has none" into a refusal belongs to the scenario that asked.
 *
 * @internal
 */
class PortalRestrictionGateway
{
	/** Licenses on which the platform demands a verified phone before a form may be embedded. */
	private const PHONE_VERIFIED_LICENSES = ['project', 'demo'];

	/** Host serving public links of a cloud portal that has no domain of its own. */
	private const PROXY_HOST = 'bitrix24public.com';

	/** Section of the portal the proxy host serves; nothing outside it is proxied. */
	private const PUBLIC_SECTION = '/pub';

	public function isPhoneVerificationRequired(): bool
	{
		if (!$this->isPlatformAvailable())
		{
			return false;
		}

		return in_array(\CBitrix24::getLicenseType(), self::PHONE_VERIFIED_LICENSES, true);
	}

	public function isPhoneVerified(int $formId): bool
	{
		if (!$this->isPlatformAvailable())
		{
			return true;
		}

		return (new PhoneVerify(Form::PHONE_VERIFY_ENTITY, (string)$formId))->isVerified();
	}

	/**
	 * Public links of a cloud portal without a domain of its own are served by a separate host, under
	 * which the portal keeps its own path prefix. Everywhere else - a self-hosted portal, a cloud
	 * portal with its own domain, a link leading outside the public section - the link is final.
	 */
	public function proxyPublicUrl(string $url): string
	{
		if (!$this->isPlatformAvailable() || \CBitrix24::isCustomDomain())
		{
			return $url;
		}

		$uri = new Uri($url);
		$path = $uri->getPath();
		if (!str_starts_with($path, self::PUBLIC_SECTION . '/'))
		{
			return $url;
		}

		return $uri
			->setPath('/' . $uri->getHost() . mb_substr($path, mb_strlen(self::PUBLIC_SECTION)))
			->setHost(self::PROXY_HOST)
			->getLocator()
		;
	}

	protected function isPlatformAvailable(): bool
	{
		return Loader::includeModule('bitrix24');
	}
}
