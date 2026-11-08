<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\MailTemplate;

use Bitrix\Crm\V2\Internal\Service\MailTemplate\MailTemplateEditorService;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Uri;

class MailTemplateEditorComponent extends \CBitrixComponent implements Controllerable, Errorable
{
	private ?ErrorCollection $errors = null;

	public function configureActions(): array
	{
		return [
			'save' => [
				'prefilters' => [
					new ActionFilter\Authentication(),
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
					new ActionFilter\ContentType([ActionFilter\ContentType::JSON]),
				],
			],
		];
	}

	public function saveAction(): ?array
	{
		Loc::loadMessages(__FILE__);
		$this->errors = new ErrorCollection();
		$input = $this->request->getJsonList();
		$result = $this->getEditorService()->save(
			$input->getRaw('id'),
			$input->getRaw('fields'),
			(int)CurrentUser::get()->getId(),
		);
		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$code = (string)$error->getCode();
				$field = $error->getCustomData()['field'] ?? null;
				$messageCode = $code === 'VALIDATION_ERROR' && $field === 'title'
					? 'CRM_MAIL_TEMPLATE_EDITOR_SAVE_TITLE_REQUIRED'
					: 'CRM_MAIL_TEMPLATE_EDITOR_SAVE_' . $code;
				$this->errors->setError(new Error(
					(string)Loc::getMessage($messageCode),
					$code,
					$error->getCustomData(),
				));
			}

			return null;
		}

		return $result->getData();
	}

	public function getErrors(): array
	{
		return $this->errors?->toArray() ?? [];
	}

	public function getErrorByCode(mixed $code): ?Error
	{
		return $this->errors?->getErrorByCode($code);
	}

	public function executeComponent(): void
	{
		Loc::loadMessages(__FILE__);
		Context::getCurrent()->getResponse()->addHeader('Cache-Control', 'no-store, private');

		if ($this->request->getRequestMethod() !== 'GET')
		{
			Context::getCurrent()->getResponse()->addHeader('Allow', 'GET');
			$this->showFailure('VALIDATION_ERROR', '405 Method Not Allowed');

			return;
		}

		$id = $this->parseElementId($this->request->getQuery('element_id'));
		$siteId = $this->request->getQuery('site');
		if ($id === null || !is_string($siteId) || $siteId === '')
		{
			$this->showFailure('VALIDATION_ERROR', '400 Bad Request');

			return;
		}

		$service = $this->getEditorService();
		$result = $service->load($id, (int)CurrentUser::get()->getId(), $siteId);
		if (!$result->isSuccess())
		{
			$code = $result->getErrors()[0]->getCode();
			if ($code === 'FEATURE_DISABLED')
			{
				$path = $service->getListPath($siteId) . ($id > 0 ? 'edit/' . $id . '/' : 'add/');
				$uri = new Uri($path);
				$uri->addParams($this->getIframeParameters());
				\LocalRedirect($uri->getUri(), false, '302 Found');

				return;
			}

			$status = match ($code)
			{
				'VALIDATION_ERROR' => '400 Bad Request',
				'NOT_FOUND' => '404 Not Found',
				default => '403 Forbidden',
			};
			$this->showFailure((string)$code, $status);

			return;
		}

		$data = $result->getData();
		$formId = 'crm_mail_template_editor_' . Random::getString(12);
		$this->arResult = [
			'TEMPLATE' => $data['template'],
			'TITLE' => Loc::getMessage(
				$id > 0 ? 'CRM_MAIL_TEMPLATE_EDITOR_TITLE_EDIT' : 'CRM_MAIL_TEMPLATE_EDITOR_TITLE_ADD',
			),
			'CONTAINER_ID' => $formId . '_app',
			'EDITOR_CONTAINER_ID' => $formId . '_container',
			'FORM_ID' => $formId,
			'EDITOR_ID' => $formId . '_editor',
			'POST_FORM_PARAMS' => $this->preparePostFormParameters($formId, $data),
		];
		$GLOBALS['APPLICATION']->SetTitle($this->arResult['TITLE']);
		$this->includeComponentTemplate();
	}

	protected function getEditorService(): MailTemplateEditorService
	{
		return new MailTemplateEditorService();
	}

	private function parseElementId(mixed $value): ?int
	{
		if ($value === null)
		{
			return 0;
		}
		if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)
		{
			return null;
		}

		$value = ltrim($value, '0');
		$maximum = (string)PHP_INT_MAX;
		if (
			strlen($value) > strlen($maximum)
			|| (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
		)
		{
			return null;
		}

		return (int)$value;
	}

	private function getIframeParameters(): array
	{
		$parameters = [];
		$iframe = $this->request->getQuery('IFRAME');
		if (in_array($iframe, ['Y', 'N'], true))
		{
			$parameters['IFRAME'] = $iframe;
		}
		if ($this->request->getQuery('IFRAME_TYPE') === 'SIDE_SLIDER')
		{
			$parameters['IFRAME_TYPE'] = 'SIDE_SLIDER';
		}

		return $parameters;
	}

	private function showFailure(string $code, string $status): void
	{
		$this->arResult = [];
		Context::getCurrent()->getResponse()->setStatus($status);
		$messageCode = match ($code)
		{
			'VALIDATION_ERROR' => 'CRM_MAIL_TEMPLATE_EDITOR_INVALID_REQUEST',
			'NOT_FOUND' => 'CRM_MAIL_TEMPLATE_EDITOR_NOT_FOUND',
			default => 'CRM_MAIL_TEMPLATE_EDITOR_ACCESS_DENIED',
		};
		\ShowError((string)Loc::getMessage($messageCode));
	}

	private function preparePostFormParameters(string $formId, array $data): array
	{
		$template = $data['template'];
		$copilot = $template['copilotParams'];

		return [
			'FORM_ID' => $formId,
			'SHOW_MORE' => 'N',
			'isAiImageEnabled' => false,
			'PARSER' => [
				'Bold', 'Italic', 'Underline', 'Strike', 'ForeColor',
				'FontList', 'FontSizeList', 'RemoveFormat', 'Quote', 'Code', 'Source', 'Table',
				'CreateLink', 'Image', 'UploadImage', 'Justify', 'InsertOrderedList', 'InsertUnorderedList',
			],
			'BUTTONS' => ['UploadImage', 'UploadFile', 'Copilot'],
			'TEXT' => [
				'INPUT_NAME' => 'BODY',
				'VALUE' => $template['bodyHtml'],
				'SHOW' => 'Y',
			],
			'PROPERTIES' => [
				[
					'USER_TYPE_ID' => 'disk_file',
					'USER_TYPE' => [
						'TAG' => 'bxacid:#id#',
						'REGEXP' => '/(?:bxacid):(n?\d+)/ig',
					],
					'FIELD_NAME' => 'FILES[]',
					'VALUE' => $data['previewFiles'],
					'HIDE_CHECKBOX_ALLOW_EDIT' => 'Y',
					'HIDE_CHECKBOX_PHOTO_TEMPLATE' => 'Y',
				],
			],
			'LHE' => [
				'id' => $formId . '_editor',
				'height' => 250,
				'lazyLoad' => true,
				'bbCode' => false,
				'setFocusAfterShow' => false,
				'useFileDialogs' => false,
				'useLinkStat' => false,
				'uploadImagesFromClipboard' => false,
				'autoLink' => true,
				'isMentionUnavailable' => true,
				'isCopilotEnabled' => $copilot['isCopilotEnabled'] ?? false,
				'isCopilotTextEnabledBySettings' => $copilot['isCopilotTextEnabled'] ?? false,
				'isCopilotImageEnabledBySettings' => false,
				'copilotParams' => [
					'moduleId' => $copilot['moduleId'] ?? 'crm',
					'contextId' => $copilot['contextId'] ?? 'bxhtmled_copilot',
					'category' => $copilot['category'] ?? null,
					'invitationLineMode' => $copilot['invitationLineMode'] ?? 'eachLine',
					'contextParameters' => $copilot['contextParameters'] ?? [],
				],
				'controlsMap' => $this->getEditorControls(),
			],
		];
	}

	private function getEditorControls(): array
	{
		$controls = [];
		$expandedControls = [
			'FontSelector', 'FontSize', 'AlignList', 'InsertImage', 'InsertTable', 'Fullscreen', 'BbCode',
		];
		foreach ([
			'Bold', 'Italic', 'Underline', 'Strikeout', 'RemoveFormat', 'Color', 'FontSelector', 'FontSize',
			'OrderedList', 'UnorderedList', 'AlignList', 'InsertLink', 'InsertImage', 'InsertTable',
			'Code', 'Quote', 'Fullscreen', 'BbCode', 'More',
		] as $index => $id)
		{
			$controls[] = [
				'id' => $id,
				'compact' => !in_array($id, $expandedControls, true),
				'sort' => ($index + 1) * 10,
			];
		}

		return $controls;
	}
}
