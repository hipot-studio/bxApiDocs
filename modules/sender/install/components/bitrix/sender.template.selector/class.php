<?

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sender\Message;
use Bitrix\Sender\Security;
use Bitrix\Sender\TemplateTable;
use Bitrix\Sender\Templates;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!Bitrix\Main\Loader::includeModule('sender'))
{
	ShowError('Module `sender` not installed');
	die();
}

Loc::loadMessages(__FILE__);

class SenderTemplateSelectorComponent extends \Bitrix\Sender\Internals\CommonSenderComponent implements Controllerable
{
	private const SELECTOR_VERSION = 2;

	/** @var ErrorCollection $errors */
	protected $errors;

	protected function checkRequiredParams()
	{
		return true;
	}

	protected function initParams()
	{
		if (!isset($this->arParams['MESSAGE_CODE']))
		{
			$this->arParams['MESSAGE_CODE'] = Message\iBase::CODE_MAIL;
		}
		$this->arParams['IS_TRIGGER'] = isset($this->arParams['IS_TRIGGER']) ? (bool) $this->arParams['IS_TRIGGER'] : false;
	}

	protected function prepareResult()
	{
		$this->arResult['ACTION_URI'] = $this->getPath() . '/ajax.php';
		$this->arResult['GRID'] = array(
			'rows'=> array(),
			'items'=> array(),
			'type'=> $this->arParams['MESSAGE_CODE'],
		);

		$selector = Templates\Selector::create()
			->withMessageCode($this->arParams['MESSAGE_CODE'])
			->withVersion(self::SELECTOR_VERSION)
			->withTriggers($this->arParams['IS_TRIGGER']);

		$this->arResult['GRID']['rows'] = $this->prepareGridRows($selector->getCategories());
		$templateCounter = 0;
		foreach ($selector->getList() as $template)
		{
			$this->arResult['GRID']['items'][] = $this->prepareGridItem($template, ++$templateCounter);
		}

		return true;
	}

	/**
	 * Whether there is anything left beyond the drawn templates is asked of the row, not of its
	 * items: the answer belongs to the page of the row and only the "USER" row is paged.
	 */
	private function prepareGridRows(array $categories): array
	{
		$rows = array();
		foreach ($categories as $category)
		{
			$category['hasMore'] = $category['id'] === TemplateTable::USER_TYPE
				&& TemplateTable::hasMoreUserTemplates();
			$rows[] = $category;
		}

		return $rows;
	}

	private function prepareGridItem(array $template, int $number): array
	{
		$messageFields = array();
		foreach ($template['FIELDS'] as $field)
		{
			$onDemand = isset($field['ON_DEMAND']) && $field['ON_DEMAND'];
			$messageFields[] = array(
				'code' => $field['CODE'],
				'value' => $onDemand ? null : $field['VALUE'],
				'onDemand' => $onDemand,
			);
		}

		return array(
			'id' => $template['TYPE'] . '|' . $template['ID'] . '|' . $number,
			'name' => $template['NAME'] ?? '',
			'description' => $template['DESC'] ?? '',
			'image' => $template['ICON'] ?? '',
			'image2x' => $template['ICON_2X'] ?? '',
			'hot' => $template['HOT'] ?? '',
			'hint' => $template['HINT'] ?? '',
			'rowId' => $template['CATEGORY'] ?? '',
			'data' => array(
				'templateId' => $template['ID'] ?? null,
				'templateType' => $template['TYPE'] ?? '',
				'messageFields' => $messageFields,
				'segments' => $template['SEGMENTS'] ?? '',
				'dispatch' => $template['DISPATCH'] ?? '',
			),
		);
	}

	protected function printErrors()
	{
		foreach ($this->errors as $error)
		{
			ShowError($error);
		}
	}

	public function executeComponent()
	{
		parent::executeComponent();
		parent::prepareResultAndTemplate();
	}

	public function configureActions(): array
	{
		return array();
	}

	/**
	 * Loads the next page of user templates.
	 *
	 * @param int $lastId ID of the last already shown template.
	 * @param string $templateType Type of the templates to load.
	 * @param string $messageCode Message code of the selector the templates are asked for. Required:
	 *  a code of its own would sift the page by somebody else's rules without a word about it.
	 * @return array{items: array, hasMore: bool} Items of the page and whether anything is left
	 *  beyond it. No items means nothing is left: the client continues from the last item it got,
	 *  so an empty answer with something left behind it would leave it asking for the same page.
	 * @throws AccessDeniedException
	 */
	public function loadAction(int $lastId, string $templateType, string $messageCode): array
	{
		if (!Security\Access::getInstance()->canViewLetters())
		{
			throw new AccessDeniedException();
		}

		// user templates are built for mail only, so another code would sift out every page
		// while the loop below walks the whole table looking for one that passes
		if (
			$templateType !== TemplateTable::USER_TYPE
			|| $messageCode !== Message\iBase::CODE_MAIL
		)
		{
			return array('items' => array(), 'hasMore' => false);
		}

		$items = array();
		do
		{
			$page = TemplateTable::getUserTemplatePage($lastId);
			foreach ($page['templates'] as $template)
			{
				$lastId = (int)$template['ID'];
				if ($this->isTemplateOfSelector($template, $messageCode))
				{
					$items[] = $this->prepareGridItem($template, count($items) + 1);
				}
			}
		}
		while ($items === array() && $page['hasMore']);

		return array(
			'items' => $items,
			'hasMore' => $page['hasMore'],
		);
	}

	/**
	 * The first page is sifted by Templates\Selector, so the loaded page is sifted by the same rules.
	 *
	 * @see \Bitrix\Sender\Templates\Selector::checkTemplate()
	 */
	private function isTemplateOfSelector(array $template, string $messageCode): bool
	{
		$templateCodes = (array)$template['MESSAGE_CODE'];

		return in_array($messageCode, $templateCodes, true)
			&& (int)$template['VERSION'] >= self::SELECTOR_VERSION
			&& !$template['IS_TRIGGER']
		;
	}

	public function getEditAction()
	{
		return \Bitrix\Sender\Access\ActionDictionary::ACTION_TEMPLATE_EDIT;
	}

	public function getViewAction()
	{
		return \Bitrix\Sender\Access\ActionDictionary::ACTION_TEMPLATE_VIEW;
	}
}
