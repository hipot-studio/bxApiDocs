<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item\Group;

use Bitrix\Crm\Component\EntityList\Grid\Panel\ApplyButton;
use Bitrix\Crm\Component\EntityList\Grid\Panel\Event;
use Bitrix\Crm\Component\EntityList\Grid\Settings\ItemSettings;
use Bitrix\Crm\Integration\Analytics\Builder\Communication\WhatsAppConnectEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\MessageSender\MassWhatsApp\SendItem;
use Bitrix\Crm\Settings;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Action\Group\GroupChildAction;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\DefaultValue;
use Bitrix\Main\Grid\Panel\Snippet\Onchange;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;
use Bitrix\MessageService;

final class WhatsAppMessageChildAction extends GroupChildAction
{
	public function __construct(private int $entityTypeId, private ?int $categoryId, private string $gridId)
	{
	}

	public static function isAvailable(int $entityTypeId, ItemSettings $gridSettings): bool
	{
		if ($gridSettings->getClientTab() !== null)
		{
			return false;
		}

		if (!Settings\Crm::isWhatsAppScenarioEnabled())
		{
			return false;
		}

		$sender = SmsManager::getSenderById(SendItem::DEFAULT_PROVIDER);

		if (!$sender || !$sender::isSupported())
		{
			return false;
		}

		if ($entityTypeId === \CCrmOwnerType::Contact)
		{
			return true;
		}

		if ($entityTypeId === \CCrmOwnerType::Company && !$gridSettings->isMyCompany())
		{
			return true;
		}

		return false;
	}

	public static function getId(): string
	{
		return 'whatsapp_message';
	}

	public function getName(): string
	{
		return (string)Loc::getMessage('CRM_GRID_PANEL_GROUP_ACTION_WHATSAPP_MESSAGE');
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		return null;
	}

	protected function getOnchange(): Onchange
	{
		$sender = SmsManager::getSenderById(SendItem::DEFAULT_PROVIDER);
		$isWhatsAppEdnaEnabled = $this->isWhatsAppEdnaEnabled($sender);
		$ednaManageUrl = $this->getEdnaManageUrl($sender);
		if (!$isWhatsAppEdnaEnabled)
		{
			$ednaManageUrl = $this->updateAnalyticsParams($ednaManageUrl);
		}

		$onchange = new Onchange();
		$onchange->addAction([
			'ACTION' => Actions::SHOW,
			'DATA' => [
				['ID' => DefaultValue::FOR_ALL_CHECKBOX_ID],
			],
		]);

		$onchange->addAction([
			'ACTION' => Actions::CREATE,
			'DATA' => [
				ApplyButton::forGrid($this->gridId, [
					'ONCHANGE' => [
						[
							'ACTION' => Actions::CALLBACK,
							'DATA' => [
								[
									'JS' => (new Event('BatchManager:whatsappMessage'))
										->addEntityTypeId($this->entityTypeId)
										->addGridId($this->gridId)
										->addParam('categoryId', $this->categoryId)
										->addParam('isWhatsAppEdnaEnabled', $isWhatsAppEdnaEnabled)
										->addParam('ednaManageUrl', $ednaManageUrl)
										->buildJsCallback(),
								],
							],
						]
					],
				]),
			],
		]);

		return $onchange;
	}

	private function isWhatsAppEdnaEnabled(?MessageService\Sender\Base $sender): bool
	{
		if (!$sender)
		{
			return false;
		}

		return $sender::isSupported() && $sender->canUse();
	}

	private function getEdnaManageUrl(?MessageService\Sender\Base $sender): string
	{
		if (!$sender)
		{
			return '/contact_center/connector/?ID=whatsappbyedna';
		}

		return $sender->getManageUrl();
	}

	private function updateAnalyticsParams(string $ednaManageUrl): string
	{
		$section = Dictionary::getSectionByEntityType($this->entityTypeId, $this->categoryId);

		$current = new Uri($ednaManageUrl);
		$current->addParams([
			'analytics' => WhatsAppConnectEvent::createDefault($section)
				->setSubSection(Dictionary::SUB_SECTION_LIST)
				->buildData(),
		]);

		return $current->getUri();
	}
}
