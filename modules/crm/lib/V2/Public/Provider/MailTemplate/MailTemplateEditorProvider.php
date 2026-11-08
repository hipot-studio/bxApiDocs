<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\MailTemplate;

use Bitrix\Crm\V2\Internal\Service\MailTemplate\MailTemplateEditorAvailability;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Web\Uri;

final class MailTemplateEditorProvider
{
	private readonly MailTemplateEditorAvailability $availability;

	public function __construct()
	{
		$this->availability = ServiceLocator::getInstance()->get(MailTemplateEditorAvailability::class);
	}

	public function isAvailable(int $userId): bool
	{
		return $this->availability->isAvailable($userId);
	}

	public function getCreateUrl(string $siteId): string
	{
		return $this->getUrl($siteId, '0');
	}

	public function getEditUrlTemplate(string $siteId): string
	{
		return $this->getUrl($siteId, '#element_id#');
	}

	private function getUrl(string $siteId, string $elementId): string
	{
		if (!$this->availability->isValidSiteId($siteId))
		{
			throw new ArgumentException('An active portal site ID is required.', 'siteId');
		}

		$uri = new Uri('/bitrix/components/bitrix/crm.mail_template.editor/slider.php');
		$uri->addParams(['site' => $siteId]);

		// Keep the component path placeholder literal; Uri would encode its hashes.
		return $uri->getUri() . '&element_id=' . $elementId;
	}
}
