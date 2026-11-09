<?php

namespace Bitrix\Landing\Site\Update;

use Bitrix\Landing\Debug;
use Bitrix\Landing\Internals;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;

Loader::includeModule('landing');
Loader::includeModule('salescenter');

Loc::loadMessages(__FILE__);
Loc::loadMessages(Manager::getDocRoot() . '/bitrix/components/bitrix/landing.demo/data/page/store-chats-dark/catalog/.description.php');

class ChatSalesOrder extends Update
{
	/**
	 * This updater expects only this code.
	 */
	private const ONLY_CODES = [
		'store-chats-dark',
		'store-chats-light',
		'store-chats',
	];

	private const PAGE_CODE = 'store-chats-dark/order';

	/**
	 * Event log type of the blocks whose COMPONENT_PARAMS are not updated.
	 */
	private const LOG_TYPE_SKIPPED_BLOCK = 'LANDING_UPDATE_CHAT_SALES_ORDER';

	private static function getExistsPageId(int $siteId, string $code): ?int
	{
		$res = Landing::getList([
			'select' => [
				'ID'
			],
			'filter' => [
				'SITE_ID' => $siteId,
				'=TPL_CODE' => $code,
				'=PUBLIC' => 'Y',
			],
		]);
		if ($page = $res->fetch())
		{
			return $page['ID'];
		}
		return null;
	}
	private static function getLandingHooks(string $pageId): ?array
	{
		$res = Internals\HookDataTable::getList([
			'select' => [
				'ID', 'HOOK', 'CODE', 'VALUE', 'PUBLIC'
			],
			'filter' => [
				'=ENTITY_TYPE' => 'L',
				'ENTITY_ID' => $pageId,
			],
		]);
		if ($rows = $res->fetchAll())
		{
			return $rows;
		}
		return null;
	}

	private static function getLandingBlocks(string $pageId): ?array
	{
		$res = Internals\BlockTable::getList([
			'select' => [
				'ID', 'CODE', 'CONTENT'
			],
			'filter' => [
				'LID' => $pageId,
			],
		 ]);
		if ($rows = $res->fetchAll())
		{
			return $rows;
		}
		return null;
	}

	private static function getNewLandingBlocksData(): array
	{
		return [
			'store.salescenter.order.details' => [
				'COMPONENT_PARAMS' => [
					'TEMPLATE_MODE' => 'graymode',
				],
			],
			'61.1.phone_w_btn_rght' => [
				'COMPONENT_PARAMS' => [
					'TEMPLATE_MODE' => 'graymode',
					'BUTTON_CLASSES' => 'btn g-rounded-50 g-btn-type-outline g-btn-px-l g-btn-size-md g-btn-darkgray text-uppercase',
				],
				'CLASSES' => [
					'landing-block g-pt-20 g-pb-0 g-bg-transparent u-block-border-none',
				],
			],
			'26.separator' => [
				'CONTENT' => '<section class="landing-block g-bg-transparent g-pt-20 g-pb-10" style="">'
					. PHP_EOL . '<hr class="landing-block-line g-brd-gray-dark-v2 my-0" style="" />'
					. PHP_EOL . '</section>',
			],
		];
	}

	private static function prepareLandingBlocks($currentBlocksData, $newBlocksData): void
	{
		$newBlocksDataFiltered = array_filter($newBlocksData, static function($newBlockData) {
			return isset($newBlockData['CONTENT']) || isset($newBlockData['COMPONENT_PARAMS']) || isset($newBlockData['CLASSES']);
		});

		$currentBlocksDataProcessed = array_map(static function($currentBlockData) use ($newBlocksDataFiltered) {
			foreach ($newBlocksDataFiltered as $codeBlock => $newBlockData)
			{
				if ($codeBlock === $currentBlockData['CODE'])
				{
					if (isset($newBlockData['CONTENT']))
					{
						$currentBlockData['CONTENT'] = $newBlockData['CONTENT'];
					}
					if (isset($newBlockData['COMPONENT_PARAMS']))
					{
						$currentBlockData['CONTENT'] = self::updateComponentParams(
							(int)$currentBlockData['ID'],
							(string)$currentBlockData['CONTENT'],
							$newBlockData['COMPONENT_PARAMS']
						);
					}
					if (isset($newBlockData['CLASSES']))
					{
						$pos = strpos($newBlockData['CLASSES'][0], ' ');
						$string = substr($newBlockData['CLASSES'][0], 0, $pos + 1);
						$pattern = '/class="'. $string . '.*"/';
						$replacement = 'class="' . $newBlockData['CLASSES'][0] . '"';
						$currentBlockData['CONTENT'] = preg_replace($pattern, $replacement, $currentBlockData['CONTENT']);
					}
					return $currentBlockData;
				}
			}
			return $currentBlockData;
		}, $currentBlocksData);

		$preparedBlocksData = array_filter($currentBlocksDataProcessed);

		//update
		foreach ($preparedBlocksData as $key => $preparedBlockData)
		{
			if ($preparedBlockData['CONTENT'] === $currentBlocksData[$key]['CONTENT'])
			{
				continue;
			}
			Internals\BlockTable::update(
				$preparedBlockData['ID'],
				['CONTENT' => $preparedBlockData['CONTENT']]
			);
		}
	}

	/**
	 * Sets parameters of the single component call in the block content.
	 * Only the parameters array of the call is changed: other pairs and the code outside the call keep their bytes.
	 * If the content has no single call, its parameters array is not found or the edit fails,
	 * the content is returned as is and the block is written to the event log.
	 * @param int $blockId Block id.
	 * @param string $content Block content.
	 * @param array $params Parameters to set (name => value).
	 * @return string
	 */
	private static function updateComponentParams(int $blockId, string $content, array $params): string
	{
		// any error of the parsing or of the edit (also of the check of the result) keeps the content as is
		try
		{
			$callCount = count(\PHPParser::ParseScript($content));
			if ($callCount !== 1)
			{
				self::logSkippedBlock($blockId, 'component calls found: ' . $callCount);

				return $content;
			}

			$updatedContent = $content;
			foreach ($params as $name => $value)
			{
				$updatedContent = self::setComponentParam($updatedContent, (string)$name, (string)$value);
				if ($updatedContent === null)
				{
					self::logSkippedBlock($blockId, 'the parameters array of the call cannot be edited');

					return $content;
				}
			}

			token_get_all($updatedContent, TOKEN_PARSE);
		}
		catch (\Throwable $exception)
		{
			// the message may quote the code of the block, so only the class goes to the log
			self::logSkippedBlock($blockId, 'exception ' . $exception::class);

			return $content;
		}

		return $updatedContent;
	}

	/**
	 * Writes to the event log that COMPONENT_PARAMS of the block are not updated and its content is kept.
	 * The content and the parameter values stay out of the log.
	 * @param int $blockId Block id.
	 * @param string $reason Short reason.
	 * @return void
	 */
	private static function logSkippedBlock(int $blockId, string $reason): void
	{
		try
		{
			Debug::log(
				$blockId,
				'COMPONENT_PARAMS of the block are not updated, the content is kept: ' . $reason,
				self::LOG_TYPE_SKIPPED_BLOCK
			);
		}
		catch (\Throwable)
		{
			// logging is best-effort and must not break the update
		}
	}

	/**
	 * Replaces the value of the parameter in the parameters array of the single component call
	 * or adds the parameter to the end of the array.
	 * @param string $content Block content.
	 * @param string $name Parameter name.
	 * @param string $value Parameter value.
	 * @return string|null Null if the parameters array is not found or its elements cannot be read.
	 */
	private static function setComponentParam(string $content, string $name, string $value): ?string
	{
		$tokens = token_get_all($content);
		$bounds = self::findComponentParamsArray($tokens);
		if ($bounds === null)
		{
			return null;
		}
		[$open, $close] = $bounds;
		$elements = self::splitArrayElements($tokens, $open, $close);
		if ($elements === null)
		{
			return null;
		}

		// index of the first token => [index of the last replaced token, replacement]
		$edits = [];
		$previousSeparator = $open;
		$previousRemoval = null;
		$isFound = false;
		foreach ($elements as $i => $element)
		{
			$removal = null;
			if (self::getArrayElementKey($tokens, $element) === $name)
			{
				$valueStart = self::getNextSignificantToken($tokens, $element['ARROW']);
				if ($valueStart === null || $valueStart > $element['LAST'])
				{
					return null;
				}

				if (!$isFound)
				{
					$edits[$valueStart] = [$element['LAST'], self::exportPhpString($value)];
					$isFound = true;
				}
				// duplicate key: the pair is removed with its separator
				elseif ($element['COMMA'] !== null)
				{
					$removal = $previousSeparator + 1;
					$edits[$removal] = [$element['COMMA'], ''];
				}
				elseif ($previousRemoval !== null)
				{
					$edits[$previousRemoval][0] = $element['LAST'];
				}
				else
				{
					$removal = $elements[$i - 1]['COMMA'];
					$edits[$removal] = [$element['LAST'], ''];
				}
			}
			$previousSeparator = $element['COMMA'] ?? $element['LAST'];
			$previousRemoval = $removal;
		}

		if (!$isFound)
		{
			$pair = self::exportPhpString($name) . ' => ' . self::exportPhpString($value);
			$lastElement = end($elements);
			if ($lastElement === false)
			{
				$edits[$open + 1] = [$open, $pair];
			}
			elseif ($lastElement['COMMA'] !== null)
			{
				$indent = self::getArrayElementIndent($tokens, $lastElement);
				$edits[$lastElement['COMMA'] + 1] = [$lastElement['COMMA'], $indent . $pair . ','];
			}
			else
			{
				$indent = self::getArrayElementIndent($tokens, $lastElement);
				$edits[$lastElement['LAST'] + 1] = [$lastElement['LAST'], ',' . $indent . $pair];
			}
		}

		$result = '';
		$count = count($tokens);
		for ($i = 0; $i < $count; $i++)
		{
			if (isset($edits[$i]))
			{
				[$last, $replacement] = $edits[$i];
				$result .= $replacement;
				// an insertion keeps the current token, a replacement skips the replaced ones
				if ($last >= $i)
				{
					$i = $last;
					continue;
				}
			}
			$result .= self::getTokenText($tokens[$i]);
		}

		return $result;
	}

	/**
	 * Returns indexes of the brackets of the parameters array (the third argument)
	 * of the single IncludeComponent call if the argument consists of an array literal only.
	 * @param array $tokens Tokens of the content.
	 * @return array|null
	 */
	private static function findComponentParamsArray(array $tokens): ?array
	{
		$callOpen = null;
		foreach ($tokens as $i => $token)
		{
			if (
				is_array($token)
				&& $token[0] === T_STRING
				&& strcasecmp($token[1], 'IncludeComponent') === 0
			)
			{
				$prev = self::getPrevSignificantToken($tokens, $i);
				$next = self::getNextSignificantToken($tokens, $i);
				if (
					$prev !== null && $next !== null
					&& is_array($tokens[$prev])
					&& in_array(
						$tokens[$prev][0],
						[T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON],
						true
					)
					&& $tokens[$next] === '('
				)
				{
					if ($callOpen !== null)
					{
						return null;
					}
					$callOpen = $next;
				}
			}
		}
		if ($callOpen === null)
		{
			return null;
		}

		// the first token of the third argument
		$start = null;
		$depth = 0;
		$argument = 0;
		$count = count($tokens);
		for ($i = $callOpen + 1; $i < $count; $i++)
		{
			$token = $tokens[$i];
			if (self::isIgnorableToken($token))
			{
				continue;
			}
			if ($depth === 0)
			{
				if ($token === ')')
				{
					return null;
				}
				if ($token === ',')
				{
					$argument++;
					continue;
				}
				if ($argument === 2)
				{
					$start = $i;
					break;
				}
			}
			if (self::isOpeningToken($token))
			{
				$depth++;
			}
			elseif (self::isClosingToken($token))
			{
				$depth--;
			}
		}
		if ($start === null)
		{
			return null;
		}

		// the brackets of the array literal: [...] or array(...)
		$open = $start;
		if (is_array($tokens[$start]) && $tokens[$start][0] === T_ARRAY)
		{
			$open = self::getNextSignificantToken($tokens, $start);
			if ($open === null || $tokens[$open] !== '(')
			{
				return null;
			}
		}
		elseif ($tokens[$start] !== '[')
		{
			return null;
		}

		$depth = 0;
		$close = null;
		for ($i = $open; $i < $count; $i++)
		{
			if (self::isOpeningToken($tokens[$i]))
			{
				$depth++;
			}
			elseif (self::isClosingToken($tokens[$i]))
			{
				$depth--;
				if ($depth === 0)
				{
					$close = $i;
					break;
				}
			}
		}
		if ($close === null)
		{
			return null;
		}

		// the literal is the whole argument
		$next = self::getNextSignificantToken($tokens, $close);
		if ($next === null || ($tokens[$next] !== ',' && $tokens[$next] !== ')'))
		{
			return null;
		}

		return [$open, $close];
	}

	/**
	 * Splits the array literal into elements: indexes of the first and the last significant tokens,
	 * of the top level double arrow and of the separating comma.
	 * @param array $tokens Tokens of the content.
	 * @param int $open Index of the opening bracket.
	 * @param int $close Index of the closing bracket.
	 * @return array|null
	 */
	private static function splitArrayElements(array $tokens, int $open, int $close): ?array
	{
		$elements = [];
		$element = null;
		$depth = 0;
		for ($i = $open + 1; $i < $close; $i++)
		{
			$token = $tokens[$i];
			if (self::isIgnorableToken($token))
			{
				continue;
			}
			if ($depth === 0 && $token === ',')
			{
				if ($element === null)
				{
					return null;
				}
				$element['COMMA'] = $i;
				$elements[] = $element;
				$element = null;
				continue;
			}
			if ($element === null)
			{
				$element = [
					'FIRST' => $i,
					'LAST' => $i,
					'ARROW' => null,
					'COMMA' => null,
				];
			}
			$element['LAST'] = $i;
			if (
				$depth === 0
				&& $element['ARROW'] === null
				&& is_array($token)
				&& $token[0] === T_DOUBLE_ARROW
			)
			{
				$element['ARROW'] = $i;
			}
			if (self::isOpeningToken($token))
			{
				$depth++;
			}
			elseif (self::isClosingToken($token))
			{
				$depth--;
			}
		}
		if ($element !== null)
		{
			$elements[] = $element;
		}

		return $elements;
	}

	/**
	 * Returns the key of the array element if the key is a constant string.
	 * @param array $tokens Tokens of the content.
	 * @param array $element Element of splitArrayElements.
	 * @return string|null
	 */
	private static function getArrayElementKey(array $tokens, array $element): ?string
	{
		if ($element['ARROW'] === null)
		{
			return null;
		}
		$keyToken = $tokens[$element['FIRST']];
		if (
			!is_array($keyToken)
			|| $keyToken[0] !== T_CONSTANT_ENCAPSED_STRING
			|| self::getNextSignificantToken($tokens, $element['FIRST']) !== $element['ARROW']
		)
		{
			return null;
		}

		return self::parsePhpString($keyToken[1]);
	}

	/**
	 * Returns the line break and the indent before the element to place a new element the same way.
	 * @param array $tokens Tokens of the content.
	 * @param array $element Element of splitArrayElements.
	 * @return string
	 */
	private static function getArrayElementIndent(array $tokens, array $element): string
	{
		$text = '';
		for ($i = $element['FIRST'] - 1; $i >= 0 && self::isIgnorableToken($tokens[$i]); $i--)
		{
			$text = self::getTokenText($tokens[$i]) . $text;
		}

		if (preg_match_all('/(?:\r\n|\n|\r)[ \t]*/', $text, $matches))
		{
			return end($matches[0]);
		}
		if (preg_match('/[ \t]+$/', $text, $matches))
		{
			return $matches[0];
		}

		return ' ';
	}

	/**
	 * Returns the value of the constant string literal (single or double quoted, without variables).
	 * @param string $literal Literal.
	 * @return string
	 */
	private static function parsePhpString(string $literal): string
	{
		if ($literal[0] === 'b' || $literal[0] === 'B')
		{
			$literal = substr($literal, 1);
		}
		$body = substr($literal, 1, -1);

		if ($literal[0] === '\'')
		{
			return preg_replace('/\\\\([\\\\\'])/', '$1', $body);
		}

		return preg_replace_callback(
			'/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
			static function(array $matches): string {
				if ($matches[1] !== '')
				{
					return [
						'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f",
						'\\' => '\\', '$' => '$', '"' => '"',
					][$matches[1]];
				}
				if (($matches[2] ?? '') !== '')
				{
					return chr(octdec($matches[2]) & 255);
				}
				if (($matches[3] ?? '') !== '')
				{
					return chr(hexdec($matches[3]));
				}

				return mb_chr(hexdec($matches[4]), 'UTF-8');
			},
			$body
		);
	}

	/**
	 * Returns the single quoted PHP literal of the string.
	 * @param string $value String.
	 * @return string
	 */
	private static function exportPhpString(string $value): string
	{
		return '\'' . strtr($value, ['\\' => '\\\\', '\'' => '\\\'']) . '\'';
	}

	private static function getTokenText($token): string
	{
		return is_array($token) ? $token[1] : $token;
	}

	private static function isIgnorableToken($token): bool
	{
		return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
	}

	private static function isOpeningToken($token): bool
	{
		if (is_array($token))
		{
			return in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true);
		}

		return $token === '(' || $token === '[' || $token === '{';
	}

	private static function isClosingToken($token): bool
	{
		return $token === ')' || $token === ']' || $token === '}';
	}

	private static function getNextSignificantToken(array $tokens, int $index): ?int
	{
		$count = count($tokens);
		for ($i = $index + 1; $i < $count; $i++)
		{
			if (!self::isIgnorableToken($tokens[$i]))
			{
				return $i;
			}
		}

		return null;
	}

	private static function getPrevSignificantToken(array $tokens, int $index): ?int
	{
		for ($i = $index - 1; $i >= 0; $i--)
		{
			if (!self::isIgnorableToken($tokens[$i]))
			{
				return $i;
			}
		}

		return null;
	}

	private static function getNewLandingHooks(): array
	{
		return [
			[
				'HOOK' => 'BACKGROUND',
				'CODE' => 'USE',
				'VALUE' => 'Y',
			],
			[
				'HOOK' => 'BACKGROUND',
				'CODE' => 'COLOR',
				'VALUE' => '#1c1c22',
			],
			[
				'HOOK' => 'BACKGROUND',
				'CODE' => 'PICTURE',
				'VALUE' => 'https://cdn.bitrix24.site/bitrix/images/landing/bg/store-chat-gray.jpg',
			],
			[
				'HOOK' => 'BACKGROUND',
				'CODE' => 'POSITION',
				'VALUE' => 'no_repeat',
			],
			[
				'HOOK' => 'CSSBLOCK',
				'CODE' => 'USE',
				'VALUE' => 'Y',
			],
			[
				'HOOK' => 'CSSBLOCK',
				'CODE' => 'CODE',
				'VALUE' => '.landing-viewtype--mobile .landing-public-mode {outline: none;}',
			],
		];
	}

	private static function prepareLandingHooks($currentHooks, $newHooks, $pageId): void
	{
		$isUpdateBgHooks = true;
		foreach ($currentHooks as $currentHookData)
		{
			if (
				$currentHookData['HOOK'] === 'BACKGROUND'
				&& $currentHookData['CODE'] === 'USE'
				&& $currentHookData['VALUE'] === 'Y'
			)
			{
				$isUpdateBgHooks = false;
			}
		}

		$updateHooksData = [];
		$createHooksData = [];
		foreach ($newHooks as $hookData)
		{
			if ($hookData['HOOK'] === 'BACKGROUND' && $isUpdateBgHooks === false)
			{
				continue;
			}

			$isExistPublicHook = false;
			$isExistUnPublicHook = false;
			foreach ($currentHooks as $currentHookData)
			{
				if (
					$hookData['HOOK'] === $currentHookData['HOOK']
					&& $hookData['CODE'] === $currentHookData['CODE']
				)
				{
					if ($hookData['VALUE'] !== $currentHookData['VALUE'])
					{
						$updateHooksData[] = [
							'ID' => $currentHookData['ID'],
							'VALUE' => $hookData['VALUE'],
						];
					}
					$isPublicHook = $currentHookData['PUBLIC'];
					if ($isPublicHook === 'Y')
					{
						$isExistPublicHook = true;
					}
					if ($isPublicHook === 'N')
					{
						$isExistUnPublicHook = true;
					}
				}
			}
			$isNeedCreateHook = false;
			if (!$isExistPublicHook)
			{
				$isNeedCreateHook = true;
				$isPublic = 'Y';
			}
			if (!$isExistUnPublicHook)
			{
				$isNeedCreateHook = true;
				$isPublic = 'N';
			}
			if ($isNeedCreateHook === true && isset($isPublic))
			{
				$createHooksData[] = [
					'ENTITY_ID' => $pageId,
					'ENTITY_TYPE' => 'L',
					'HOOK' => $hookData['HOOK'],
					'CODE' => $hookData['CODE'],
					'VALUE' => $hookData['VALUE'],
					'PUBLIC' => $isPublic,
				];
			}
		}

		//update
		foreach ($updateHooksData as $updateHookData)
		{
			Internals\HookDataTable::update(
				$updateHookData['ID'],
				['VALUE' => $updateHookData['VALUE']]
			);
		}
		//create
		foreach ($createHooksData as $createHookData)
		{
			Internals\HookDataTable::add([
				'ENTITY_ID' => $createHookData['ENTITY_ID'],
				'ENTITY_TYPE' => $createHookData['ENTITY_TYPE'],
				'HOOK' => $createHookData['HOOK'],
				'CODE' => $createHookData['CODE'],
				'VALUE' => $createHookData['VALUE'],
				'PUBLIC' => $createHookData['PUBLIC'],
			]);
		}
	}

	/**
	 * Entry point. Returns true on success.
	 * @param int $siteId Site id.
	 * @return bool
	 */
	public static function update(int $siteId): bool
	{
		$site = self::getId($siteId);

		if (!$site || !in_array($site['TPL_CODE'], self::ONLY_CODES, true))
		{
			return true;
		}

		$orderPageId = self::getExistsPageId($siteId, self::PAGE_CODE);
		if ($orderPageId)
		{
			$currentHooks = self::getLandingHooks($orderPageId);
			$newHooks = self::getNewLandingHooks();
			self::prepareLandingHooks($currentHooks, $newHooks, $orderPageId);

			$currentBlocks = self::getLandingBlocks($orderPageId);
			$newBlocksData = self::getNewLandingBlocksData();
			self::prepareLandingBlocks($currentBlocks, $newBlocksData);
		}

		return true;
	}
}
