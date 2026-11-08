<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Rest\Sqs;
use Bitrix\Rest;
use Bitrix\Main\Web\Uri;

class CBPWebHookActivity extends CBPActivity implements IBPConfigurableActivity
{
	/** Key the payload travels under, beside the document identifier the body has always carried. */
	private const PAYLOAD_BODY_KEY = 'payload';

	/** Size of the whole body in UTF-8 bytes, measured on the encoding the event is queued with. */
	private const BODY_BYTE_LIMIT = 1048576;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = array(
			"Title" => "",
			"Handler" => "",
			"Payload" => [],
		);
	}

	public function Execute()
	{
		if(!static::checkRegister())
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$handler = $this->Handler;
		if (is_array($handler))
		{
			$handler = CBPHelper::flatten($handler)[0] ?? null;
		}

		$target = $handler ? static::buildTarget((string)$handler) : null;
		if ($target === null)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$body = ['document_id' => $this->GetDocumentId()];

		$payload = $this->resolvePayload();
		if ($payload === null)
		{
			// The message names neither a value nor the address: a diagnostic of this block is read wherever
			// the journal of the process is, and the payload is where a token of an integration ends up.
			$this->writeToTrackingService(GetMessage('BPWHA_ERROR_PAYLOAD_VALUE'), 0, CBPTrackingType::Error);

			return CBPActivityExecutionStatus::Closed;
		}

		// The key appears only when there is something to put under it, so a block with no payload sends the
		// body it has always sent.
		if ($payload)
		{
			$body[self::PAYLOAD_BODY_KEY] = $payload;
		}

		if (!static::isBodyWithinLimit($body))
		{
			$this->writeToTrackingService(GetMessage('BPWHA_ERROR_PAYLOAD_LIMIT'), 0, CBPTrackingType::Error);

			return CBPActivityExecutionStatus::Closed;
		}

		$this->sendEvent($target, $body);

		return CBPActivityExecutionStatus::Closed;
	}

	/**
	 * The address the event is sent to, rebuilt part by part so that only a http(s) url with a real host reaches
	 * the queue. Null when the handler is not such an address.
	 */
	protected static function buildTarget(string $handler): ?string
	{
		$handlerData = parse_url($handler);

		if (!is_array($handlerData)
			|| !isset($handlerData['host'])
			|| $handlerData['host'] == ''
			|| !(mb_strpos($handlerData['host'], '.') > 0)
			|| !isset($handlerData['scheme'])
			|| ($handlerData['scheme'] != 'http' && $handlerData['scheme'] != 'https')
		)
		{
			return null;
		}

		$target = $handlerData['scheme'] . '://';
		if (isset($handlerData['user']) || isset($handlerData['pass']))
		{
			$target .= $handlerData['user'];
			if (isset($handlerData['pass']))
			{
				$target .= ':'. $handlerData['pass'];
			}
			$target .= '@';
		}
		$target .= $handlerData['host'];
		if (isset($handlerData['port']))
		{
			$target .= ':'.$handlerData['port'];
		}
		if (isset($handlerData['path']))
		{
			$target .= Uri::urnEncode($handlerData['path']);
		}
		if (isset($handlerData['query']))
		{
			parse_str($handlerData['query'], $queryString);
			$target .= '?' . http_build_query($queryString);
		}
		if (isset($handlerData['fragment']))
		{
			$target .= '#' . Uri::urnEncode($handlerData['fragment']);
		}

		return $target;
	}

	/**
	 * The single point the event leaves the activity through, so a test can read the body that would be sent
	 * without a live event queue behind it.
	 */
	protected function sendEvent(string $target, array $body): void
	{
		$queryItem = Sqs::queryItem(
			null,
			$target,
			$body,
			[],
			[
				"sendAuth" => false,
				"sendRefreshToken" => false,
				"category" => Sqs::CATEGORY_BIZPROC,
			]
		);

		if (is_callable([\Bitrix\Rest\Event\Sender::class, 'queueEvent']))
		{
			\Bitrix\Rest\Event\Sender::queueEvent($queryItem);
		}
		else
		{
			\Bitrix\Rest\OAuthService::getEngine()->getClient()->sendEvent([$queryItem]);
		}
	}

	/**
	 * The payload as it goes on the wire: every value substituted on its own and then checked for being a scalar
	 * again, because a substitution is what turns a written expression into whatever the document holds - a
	 * multiple field yields a list.
	 *
	 * Null when the map cannot be sent: a value that came back nested has no place in a map the receiver reads
	 * one scalar per key from, and flattening it would deliver the literal 'Array' instead.
	 *
	 * @return array<string, scalar>|null
	 */
	private function resolvePayload(): ?array
	{
		$stored = static::normalizePayload($this->getRawPropertyValue('Payload'));
		if ($stored === null)
		{
			return null;
		}

		if (!$stored)
		{
			return [];
		}

		$payload = [];
		foreach ($stored as $name => $value)
		{
			// One value at a time: handing the whole map to the engine lets it read a two-element map as a
			// reference to a field of another block ({@see CBPActivity::getPropertyValueRecursive()}).
			$value = $this->parseValue($value);
			if (!is_scalar($value))
			{
				return null;
			}

			$payload[$name] = $value;
		}

		return $payload;
	}

	/**
	 * The body against the size the queue accepts, measured on its own encoding rather than on the map before
	 * it: the check has to answer for what is actually sent, and the substituted values are only known here.
	 */
	private static function isBodyWithinLimit(array $body): bool
	{
		try
		{
			$encoded = Json::encode($body);
		}
		catch (ArgumentException)
		{
			return false;
		}

		return strlen($encoded) <= self::BODY_BYTE_LIMIT;
	}

	/**
	 * The stored payload as a map of scalars, or null when it is not one. Null and the empty string are an empty
	 * map: a dialog saved with the field left blank writes one, and refusing it would refuse the block the
	 * setting is optional for.
	 *
	 * @return array<string, scalar>|null
	 */
	private static function normalizePayload(mixed $value): ?array
	{
		if ($value === null || $value === '')
		{
			return [];
		}

		if (!is_array($value))
		{
			return null;
		}

		$payload = [];
		foreach ($value as $name => $item)
		{
			// The same shape of a key the contour holds a free map to
			// ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentSettingValueValidator}):
			// a list decoded from the dialog arrives with integer keys, and it is not a map of names.
			if (!is_string($name) || $name === '' || !is_scalar($item))
			{
				return null;
			}

			$payload[$name] = $item;
		}

		return $payload;
	}

	/**
	 * The text of the dialog back into the map the block stores. Parsed here, before anything is substituted, so
	 * an expression written into a value can only ever produce a value of the map and never its shape.
	 *
	 * @return array<string, scalar>|null null when the text is not a JSON object of scalars
	 */
	private static function decodePayload(mixed $value): ?array
	{
		if (is_array($value))
		{
			return static::normalizePayload($value);
		}

		$value = trim((string)$value);
		if ($value === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($value);
		}
		catch (ArgumentException)
		{
			return null;
		}

		return static::normalizePayload($decoded);
	}

	/**
	 * The map as the three dialogs show it: one JSON object, because a dialog holds text where the block holds a
	 * map. A value of another shape is shown as it is stored rather than as an empty object, so opening a block
	 * never quietly drops what it carries.
	 */
	private static function encodePayload(mixed $payload): string
	{
		if (is_string($payload))
		{
			return $payload;
		}

		if (!is_array($payload) || !$payload)
		{
			return '';
		}

		try
		{
			return Json::encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		}
		catch (ArgumentException)
		{
			return '';
		}
	}

	protected static function checkRegister()
	{
		if (!Loader::includeModule('rest') || !Rest\Engine\Access::isAvailable())
		{
			return false;
		}

		if(!\Bitrix\Rest\OAuthService::getEngine()->isRegistered())
		{
			try
			{
				\Bitrix\Rest\OAuthService::register();
			}
			catch(\Bitrix\Main\SystemException $e)
			{
				return false;
			}
		}

		if(!\Bitrix\Rest\OAuthService::getEngine()->isRegistered())
		{
			return false;
		}

		return true;
	}

	public static function ValidateProperties($arTestProperties = array(), CBPWorkflowTemplateUser $user = null)
	{
		$arErrors = array();

		if ($arTestProperties["Handler"] == '')
		{
			$arErrors[] = array(
				"code" => "emptyHandler",
				"message" => GetMessage("BPWHA_EMPTY_TEXT"),
			);
		}

		if (static::normalizePayload($arTestProperties['Payload'] ?? null) === null)
		{
			$arErrors[] = [
				'code' => 'invalidPayload',
				'parameter' => 'Payload',
				'message' => GetMessage('BPWHA_INVALID_PAYLOAD'),
			];
		}

		return array_merge($arErrors, parent::ValidateProperties($arTestProperties, $user));
	}

	public static function GetPropertiesDialog($documentType, $activityName, $arWorkflowTemplate, $arWorkflowParameters, $arWorkflowVariables, $arCurrentValues = null, $formName = "", $popupWindow = null, $siteId = '')
	{
		$dialog = new \Bitrix\Bizproc\Activity\PropertiesDialog(__FILE__, array(
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId
		));

		$dialog->setMap(static::getPropertiesMap($documentType));

		return $dialog;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			'Handler' => [
				'Name' => GetMessage('BPWHA_HANDLER_NAME'),
				'Description' => GetMessage('BPWHA_HANDLER_NAME'),
				'FieldName' => 'handler',
				'Type' => \Bitrix\Bizproc\FieldType::TEXT,
				'Required' => true,
			],
			'Payload' => [
				'Name' => GetMessage('BPWHA_PAYLOAD_NAME'),
				'FieldName' => 'payload',
				'Type' => \Bitrix\Bizproc\FieldType::TEXT,
				'Required' => false,
				// The block holds a map and a dialog holds text, so the three windows are handed the map
				// serialised as one JSON object and hand it back the same way. The name is written out
				// rather than read off the property handed in: the panel of the new editor builds its
				// controls without an `Id` ({@see \Bitrix\Bizproc\Public\Activity\ActivityControlsBuilder}),
				// and a getter reading one would draw an empty field over a block that carries a payload.
				'Getter' => static function ($dialog, $property, $currentActivity)
				{
					return CBPWebHookActivity::encodePayload($currentActivity['Properties']['Payload'] ?? null);
				},
			],
		];
	}

	public static function GetPropertiesDialogValues($documentType, $activityName, &$arWorkflowTemplate, &$arWorkflowParameters, &$arWorkflowVariables, $arCurrentValues, &$arErrors)
	{
		$arErrors = array();

		$payload = static::decodePayload($arCurrentValues['payload'] ?? null);
		if ($payload === null)
		{
			$arErrors[] = [
				'code' => 'invalidPayload',
				'parameter' => 'Payload',
				'message' => GetMessage('BPWHA_INVALID_PAYLOAD'),
			];

			return false;
		}

		$arProperties = array(
			"Handler" => $arCurrentValues["handler"],
		);

		// An empty map writes no key at all, so a block saved without a payload keeps the exact shape it had
		// before the setting existed.
		if ($payload)
		{
			$arProperties['Payload'] = $payload;
		}

		$arErrors = self::ValidateProperties($arProperties, new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser));
		if (count($arErrors) > 0)
			return false;

		$arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$arCurrentActivity["Properties"] = $arProperties;

		return true;
	}
}
