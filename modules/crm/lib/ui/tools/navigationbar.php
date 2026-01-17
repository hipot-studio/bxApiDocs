<?php

namespace Bitrix\Crm\UI\Tools;

use Bitrix\Crm\RepeatSale\Widget\WidgetManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Color;
use Bitrix\UI\Buttons\Icon;
use Bitrix\UI\Buttons\JsCode;
use Bitrix\UI\Buttons\Tag;

class NavigationBar
{
	public const BINDING_MENU_MASK = '/(lead|deal|invoice|quote|company|contact|order)/i';

	private array $switchViewList = [
		'items' => [],
		'binding' => [],
	];
	private string $automationView = '';
	private string $repeatSaleView = '';
	private bool $isEnabled = true;

	public function __construct(array $input)
	{
		$this->init($input);
	}

	public function isEnabled(): bool
	{
		return $this->isEnabled;
	}

	private function getActiveView(): ?string
	{
		$viewList = $this->getSwitchViewList();
		$items = $viewList['items'] ?? [];

		foreach ($items as $item)
		{
			if ($item['active'])
			{
				return $item['id'];
			}
		}

		return null;
	}

	public function getSwitchViewList(): array
	{
		return $this->switchViewList;
	}

	public function getAutomationView(): string
	{
		return $this->automationView;
	}

	public function getAutomationViewLayout(): Button
	{
		Container::getInstance()->getLocalization()->loadMessages();

		return Button::create([
			'tag' => Tag::LINK,
			'color' => Color::LIGHT_BORDER,
			'icon' => Icon::ROBOTS,
			'classList' => [
				'crm-robot-btn', // used for custom styles and js
				'ui-btn-themes',
			],
			'noCaps' => true,
			'round' => true,
			'text' => Loc::getMessage('CRM_COMMON_ROBOTS'),
		]);
	}

	public function getRepeatSaleView(): string
	{
		return $this->repeatSaleView;
	}

	public function getRepeatSaleViewLayout(): ?Button
	{
		Container::getInstance()->getLocalization()->loadMessages();

		$config = WidgetManager::getInstance()->getWidgetConfig();
		$type = $config['type'] ?? null;
		if ($type === null)
		{
			return null;
		}

		Extension::load([
			'crm.repeat-sale.widget',
		]);

		$showConfetti = $config['showConfetti'] ?? false;
		$periodTypeId = $config['periodTypeId'] ?? null;

		$params = Json::encode([
			'showConfetti' => $showConfetti,
			'periodTypeId' => $periodTypeId,
		]);

		return new Button([
			'text' => Loc::getMessage('CRM_COMMON_REPEAT_SALE'),
			'noCaps' => true,
			'round' => true,
			'color' => Color::LIGHT_BORDER,
			'tag' => Tag::LINK,
			'icon' => Icon::COPILOT,
			'classList' => [
				'crm-repeat-sale-btn',
				'ui-btn-themes',
			],
			'onclick' => new JsCode(
				"BX.Crm.RepeatSale.Widget.execute(
					'{$type}',
					event.target,
					$params,
				)",
			),
			'dataset' => [
				'id' => 'crm-repeat-sale-widget-button',
				'subsection' => $this->getActiveView(),
			],
		]);
	}

	private function init(array $input): void
	{
		if (isset($input['~DISABLE_NAVIGATION_BAR']) && $input['~DISABLE_NAVIGATION_BAR'] === 'Y')
		{
			$this->isEnabled = false;

			return;
		}

		$data = isset($input['~NAVIGATION_BAR']) && is_array($input['~NAVIGATION_BAR'])
			? $input['~NAVIGATION_BAR']
			: [];

		if (empty($data) || empty($data['ITEMS']))
		{
			return;
		}

		$itemQty = 0;
		foreach ($data['ITEMS'] as $row)
		{
			$itemQty++;

			$itemId = $row['id'] ?? $itemQty;
			$itemName = $row['name'] ?? $itemId;
			$itemUrl = $row['url'] ?? '';
			if ($itemId === 'automation' && isset($input['COUNTER_PANEL']['ENTITY_TYPE_NAME']))
			{
				$buttonHtml = $this->getRobotButtonHtml((string)$input['COUNTER_PANEL']['ENTITY_TYPE_NAME'], (string)$itemUrl);
				if ($buttonHtml)
				{
					$this->automationView = $buttonHtml;
				}

				continue;
			}

			if ($itemId === 'repeatSale')
			{
				$button = $this->getRepeatSaleViewLayout();

				$jsInit = defined('AIR_SITE_TEMPLATE') === false;
				$this->repeatSaleView = $button?->render($jsInit) ?? '';

				continue;
			}

			$this->switchViewList['items'][] = [
				'id' => htmlspecialcharsbx($itemId),
				'title' => htmlspecialcharsbx($itemName),
				'active' => isset($row['active']) && $row['active'],
				'lockedCallback' => $row['lockedCallback'] ?? '',
				'url' => $itemUrl,
			];
		}

		if (!empty($this->switchViewList['items']))
		{
			$this->switchViewList['binding'] = $data['BINDING'] ?? [];
		}
	}

	public function getRobotButtonHtml(string $entityTypeName, ?string $link = '', ?string $onClick = ''): ?string
	{
		if (!Loader::includeModule('bizproc'))
		{
			return null;
		}

		$params = [
			'RENDER_BUTTON_TO_RESULT' => true,
		];

		if ($link)
		{
			$params['URL'] = $link;
		}
		elseif ($onClick)
		{
			$params['ON_CLICK'] = $onClick;
		}
		else
		{
			return null;
		}

		$params['DOCUMENT_TYPE'] = $this->getDocumentTypeByEntityTypeName($entityTypeName) ?? [];

		global $APPLICATION;

		$robotsButtonArResult = $APPLICATION->IncludeComponent(
			'bitrix:bizproc.automation.robot.button',
			'',
			$params,
			returnResult: true
		);

		if (!empty($robotsButtonArResult["ROBOT_BUTTON_HTML"]))
		{
			return $robotsButtonArResult["ROBOT_BUTTON_HTML"];
		}

		return null;
	}

	private function getDocumentTypeByEntityTypeName(string $entityTypeName): ?array
	{
		$entityTypeName = strtoupper($entityTypeName);

		if (preg_match('/^'.\CCrmOwnerType::DynamicTypePrefixName.'(\d+)$/', $entityTypeName))
		{
			return ['crm', \Bitrix\Crm\Integration\BizProc\Document\Dynamic::class, $entityTypeName];
		}

		$typesMap = [
			\CCrmOwnerType::LeadName => \CCrmDocumentLead::class,
			\CCrmOwnerType::DealName => \CCrmDocumentDeal::class,
			\CCrmOwnerType::ContactName => \CCrmDocumentContact::class,
			\CCrmOwnerType::CompanyName => \CCrmDocumentCompany::class,
			\CCrmOwnerType::QuoteName => \Bitrix\Crm\Integration\BizProc\Document\Quote::class,
			\CCrmOwnerType::SmartInvoiceName => \Bitrix\Crm\Integration\BizProc\Document\SmartInvoice::class,
			\CCrmOwnerType::SmartB2eDocumentName => \Bitrix\Crm\Integration\BizProc\Document\SmartB2eDocument::class,
		];

		$entity = $typesMap[$entityTypeName] ?? null;
		return $entity ? ['crm', $entity, $entityTypeName] : null;
	}
}
