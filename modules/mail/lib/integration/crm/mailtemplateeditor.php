<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Crm;

use Bitrix\Crm\V2\Public\Provider\MailTemplate\MailTemplateEditorProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;

class MailTemplateEditor
{
	private const ENTRY_PATH = '/bitrix/components/bitrix/mail.client/templates.php';

	public function isAvailable(int $userId): bool
	{
		return $userId > 0 && $this->getProvider()?->isAvailable($userId) === true;
	}

	public function getCreateUrl(string $siteId): ?string
	{
		return $this->getProvider()?->getCreateUrl($siteId);
	}

	public function getEditUrlTemplate(string $siteId): ?string
	{
		return $this->getProvider()?->getEditUrlTemplate($siteId);
	}

	public static function getListUrl(string $siteId): string
	{
		return (new Uri(self::ENTRY_PATH))->addParams(['site' => $siteId])->getUri();
	}

	public static function getAddUrl(string $siteId): string
	{
		return (new Uri(self::getListUrl($siteId)))->addParams(['action' => 'add'])->getUri();
	}

	private function getProvider(): ?object
	{
		if (!$this->includeCrmModule())
		{
			return null;
		}

		$providerClass = $this->getProviderClass();
		if (!class_exists($providerClass))
		{
			return null;
		}

		foreach (['isAvailable', 'getCreateUrl', 'getEditUrlTemplate'] as $method)
		{
			if (!method_exists($providerClass, $method))
			{
				return null;
			}
		}

		return new $providerClass();
	}

	protected function includeCrmModule(): bool
	{
		return Loader::includeModule('crm');
	}

	protected function getProviderClass(): string
	{
		return MailTemplateEditorProvider::class;
	}
}
