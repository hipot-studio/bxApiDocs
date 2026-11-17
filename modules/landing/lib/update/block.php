<?php
namespace Bitrix\Landing\Update;

use \Bitrix\Landing\Block as BlockCore;
use \Bitrix\Main\Web\DOM;
use \Bitrix\Landing\Block\ContentRenderer;
use \Bitrix\Landing\UpdateBlock;
use \Bitrix\Landing\Rights;
use \Bitrix\Landing\Internals\BlockTable;

class Block extends \Bitrix\Main\Update\Stepper
{
	/**
	 * Items count for one step.
	 */
	const STEPPER_COUNT = 1;

	/**
	 * PARAMS key a migration sets to move more than STEPPER_COUNT instances of its code in one step;
	 * a row without the key, or with a value that is not a positive int, keeps STEPPER_COUNT.
	 */
	public const PARAM_STEP_COUNT = 'stepCount';

	/**
	 * Upper bound of PARAM_STEP_COUNT: a step this size still has to land well inside the threshold
	 * of Stepper, whatever a migration asks for.
	 */
	private const STEP_COUNT_MAX = 50;

	/** Text of the comment the php of an old content is replaced with while the owner's data is exported. */
	private const PHP_MARK = 'landing-update-php';

	/**
	 * Target module for stepper.
	 * @var string
	 */
	protected static $moduleId = 'landing';

	/**
	 * Register new block for stepper update. A code already in the queue keeps the PARAMS of its
	 * earlier registration: the new PARAMS are merged in, overriding only the keys they share.
	 * @param string|string[] $codes Block codes.
	 * @param array $params Additional params.
	 * @return void
	 */
	public static function register($codes, array $params = [])
	{
		if (!is_array($codes))
		{
			$codes = [$codes];
		}

		$res = UpdateBlock::getList([
			'select' => [
				'ID', 'CODE', 'PARAMS'
			],
			'filter' => [
				'=CODE' => $codes
			]
		]);
		while ($row = $res->fetch())
		{
			UpdateBlock::update($row['ID'], [
				'LAST_BLOCK_ID' => 0,
				'PARAMS' => array_merge(
					is_array($row['PARAMS']) ? $row['PARAMS'] : [],
					$params
				)
			]);
			$codes = array_diff($codes, [$row['CODE']]);
		}

		if (!empty($codes))
		{
			foreach ($codes as $code)
			{
				UpdateBlock::add([
					'CODE' => $code,
					'PARAMS' => $params
				]);
			}
		}

		// reg stepper
		\Bitrix\Main\Update\Stepper::bindClass(
			'Bitrix\Landing\Update\Block', 'landing', 600
		);
	}

	/**
	 * Unregister block for stepper update.
	 * @param string[] $codes Block codes.
	 * @return void
	 */
	public static function unregister($codes)
	{
		if (!is_array($codes))
		{
			$codes = [$codes];
		}

		$res = UpdateBlock::getList([
			'select' => [
				'ID'
			],
			'filter' => [
				'=CODE' => $codes
			]
		]);
		while ($row = $res->fetch())
		{
			UpdateBlock::delete($row['ID']);
		}
	}

	/**
	 * Preparing css classes array before set to node.
	 * @param array|string $classes Base classes to set.
	 * @param array $addClasses New classes for base array.
	 * @param array $removeClasses Classes to remove from base array.
	 * @return array
	 */
	protected static function prepareClassesToSet($classes, array $addClasses = [], array $removeClasses = [])
	{
		if (is_array($classes) && isset($classes['classList']))
		{
			$classes = $classes['classList'];
		}
		if (!is_array($classes))
		{
			$classes = [$classes];
		}

		$classesUnique = array_unique($classes);
		// all nodes have equal classes
		if (count($classesUnique) === 1)
		{
			$result = [
				[
					'classList' => $classesUnique[0],
					'suffix' => '',
				],
			];
		}
		// different classes
		else
		{
			$classesSorted = [];
			// find most frequent class
			$counts = array_count_values($classes);
			arsort($counts);
			$mainClass = key($counts);
			foreach ($classes as $pos => $class)
			{
				if ($class !== $mainClass)
				{
					$classesSorted[] = [
						'classList' => $class,
						 'suffix' => '@' . $pos,
					];
				}
			}
			$result = array_merge(
				[
					[
						'classList' => $mainClass,
						'suffix' => '',
					],
				],
				$classesSorted
			);
		}

		// add and remove classes
		foreach ($result as $pos => $class)
		{
			if ($addClasses || $removeClasses)
			{
				$classList = explode(' ', $class['classList']);

				if ($addClasses)
				{
					$classList = array_merge($classList, $addClasses);
				}
				if ($removeClasses)
				{
					$classList = array_diff($classList, $removeClasses);
				}
				$result[$pos]['classList'] = implode(' ', array_unique($classList));
			}
		}

		return $result;
	}

	/**
	 * Execute one step.
	 * @param array $filter Filter for step.
	 * @param int &$count Updated count.
	 * @param int $limit Select limit.
	 * @param array $params Additional params.
	 * @return int Last updated id.
	 */
	public static function executeStep(array $filter, &$count = 0, $limit = 0, array $params = [])
	{
		$lastId = 0;

		Rights::setOff();

		$res = BlockTable::getList([
			'select' => [
				'ID', 'CONTENT'
			],
			'filter' => $filter,
			'order' => [
				'ID' => 'asc'
			],
			'limit' => $limit ?: null,
		]);
		while ($row = $res->fetch())
		{
			$lastId = $row['ID'];
			$count++;

			// gets content from exist block
			$block = new BlockCore($row['ID']);
			$block->setAccess(BlockCore::ACCESS_X);
			$export = self::exportOwnerData($block);

			// and apply to the new layout
			$newContent = BlockCore::getContentFromRepository(
				$block->getCode()
			);
			$block->saveContent($newContent);
			// update cards
			if ($export['cards'])
			{
				$block->updateCards(
					$export['cards']
				);
			}
			// update style
			if ($export['style'])
			{
				foreach ($export['style'] as $selector => $classes)
				{
					$addClasses = [];
					if (isset($params[$selector]['new_class']))
					{
						if (is_array($params[$selector]['new_class']))
						{
							if (count($params[$selector]['new_class']) > 1)
							{
								$addClasses = $params[$selector]['new_class'];
							}
							else
							{
								$addClasses = explode(' ', trim($params[$selector]['new_class'][0]));
							}
						}
						else
						{
							$addClasses = explode(' ', trim($params[$selector]['new_class']));
						}
					}

					$removeClasses = [];
					if (isset($params[$selector]['remove_class']))
					{
						if (is_array($params[$selector]['remove_class']))
						{
							if (count($params[$selector]['remove_class']) > 1)
							{
								$removeClasses = $params[$selector]['remove_class'];
							}
							else
							{
								$removeClasses = explode(' ', trim($params[$selector]['remove_class'][0]));
							}
						}
						else
						{
							$removeClasses = explode(' ', trim($params[$selector]['remove_class']));
						}
					}

					// change wrapper to valid selector
					if ($selector == '#wrapper')
					{
						$selector = '#' . $block->getAnchor($block->getId());
					}

					foreach (self::prepareClassesToSet($classes, $addClasses, $removeClasses) as $class)
					{
						$block->setClasses(array(
							$selector . $class['suffix'] => array(
								'classList' => [$class['classList']]
							)
						));
					}
				}
			}
			// update nodes
			if ($export['nodes'])
			{
				foreach ($export['nodes'] as $selector => $node)
				{
					if (isset($params[$selector]['update_video_to_lazyload']))
					{
						$export['nodes'][$selector] = self::updateVideoToLazyload($node);
					}
				}

				$block->updateNodes(
					$export['nodes']
				);
			}
			// update menu
			if ($export['menu'])
			{
				$block->updateNodes(
					$export['menu']
				);
			}
			// update attrs
			if ($export['attrs'])
			{
				if (isset($export['attrs']['#wrapper']))
				{
					$wrapperCode = '#' . $block->getAnchor($block->getId());
					$export['attrs'][$wrapperCode] = $export['attrs']['#wrapper'];
					unset($export['attrs']['#wrapper']);
				}
				$block->setAttributes(
					$export['attrs']
				);
			}

			// and save block with new layout and old content
			$block->save();
		}

		Rights::setOn();

		return $lastId;
	}

	/**
	 * Exports what the owner of the page put into the instance, and nothing of the php of the old
	 * template: a node or an attribute the php took part in (the php inside it, or right before it,
	 * as in a branch of a condition) keeps the value of the fresh template instead.
	 * @param BlockCore $block Instance to export.
	 * @return array
	 */
	private static function exportOwnerData(BlockCore $block): array
	{
		$params = [
			'clear_form' => false,
		];
		$content = (string)$block->getContent();
		$mark = '<!--' . self::PHP_MARK . '-->';
		$markup = ContentRenderer::withoutPhp($content, $mark);
		if ($markup === $content)
		{
			return $block->export($params);
		}

		$export = $block->exportContent($markup, $params);
		$doc = new DOM\Document();
		try
		{
			$doc->loadHTML($markup);
		}
		catch (\Exception)
		{
			return $export;
		}

		foreach (['nodes', 'attrs'] as $key)
		{
			foreach ($export[$key] ?? [] as $selector => $positions)
			{
				if (!is_array($positions) || $selector === '#wrapper')
				{
					continue;
				}
				foreach ($doc->querySelectorAll($selector) as $position => $element)
				{
					if (isset($positions[$position]) && self::isOfThePhp($element))
					{
						unset($export[$key][$selector][$position]);
					}
				}
				if (!$export[$key][$selector])
				{
					unset($export[$key][$selector]);
				}
			}
		}

		return $export;
	}

	/**
	 * Tells whether the php of the old template took part in the element: inside it, or right before
	 * it, the way a condition opens a branch.
	 * @param DOM\Node $element Element of the markup without php.
	 * @return bool
	 */
	private static function isOfThePhp(DOM\Node $element): bool
	{
		if (str_contains((string)$element->getOuterHTML(), self::PHP_MARK))
		{
			return true;
		}

		$sibling = $element->getPreviousSibling();
		while (
			$sibling
			&& $sibling->getNodeType() === DOM\Node::TEXT_NODE
			&& trim((string)$sibling->getNodeValue()) === ''
		)
		{
			$sibling = $sibling->getPreviousSibling();
		}

		return $sibling
			&& $sibling->getNodeType() === DOM\Node::COMMENT_NODE
			&& str_contains((string)$sibling->getNodeValue(), self::PHP_MARK);
	}

	/**
	 * One step of stepper.
	 * @param array &$result Result array.
	 * @return bool
	 */
	public function execute(array &$result)
	{
		$finished = true;

		// check queue
		$res = UpdateBlock::getList([
			'select' => [
				'ID', 'CODE', 'LAST_BLOCK_ID', 'PARAMS'
			],
			'order' => [
				'ID' => 'asc'
			],
			'limit' => 1
		]);
		if (!($rowUpdate = $res->fetch()))
		{
			return false;
		}

		// gets common quantity
		$res = BlockTable::getList([
			'select' => [
				new \Bitrix\Main\Entity\ExpressionField(
					'CNT', 'COUNT(*)'
				)
			],
			'filter' => [
				'=CODE' => $rowUpdate['CODE'],
				'!=DESIGNED' => 'Y',
			]
		]);

		// skip blocks that not exists
		$row = $res->fetch();
		if(!$row || (int) $row['CNT'] === 0)
		{
			UpdateBlock::delete(
				$rowUpdate['ID']
			);
			return $this->execute($result);
		}
		$result['count'] = $row['CNT'];

		// gets finished count
		$res = BlockTable::getList([
			'select' => [
				new \Bitrix\Main\Entity\ExpressionField(
					'CNT', 'COUNT(*)'
				)
			],
			'filter' => [
				'<=ID' => $rowUpdate['LAST_BLOCK_ID'],
				'=CODE' => $rowUpdate['CODE'],
				'!=DESIGNED' => 'Y',
			]
			]
		);
		if ($row = $res->fetch())
		{
			$result['steps'] = $row['CNT'];
		}

		// gets block group for update
		$lastId = $this::executeStep([
				'>ID' => $rowUpdate['LAST_BLOCK_ID'],
				'=CODE' => $rowUpdate['CODE'],
				'!=DESIGNED' => 'Y',
			],
		 	$count,
		 	self::resolveStepCount($rowUpdate['PARAMS']),
		 	$rowUpdate['PARAMS']
		);
		if ($lastId > 0)
		{
			$finished = false;
		}

		// finish or continue
		if (!$finished)
		{
			UpdateBlock::update($rowUpdate['ID'], [
				'LAST_BLOCK_ID' => $lastId
			]);
			return true;
		}
		else
		{
			UpdateBlock::delete($rowUpdate['ID']);
			return true;//for check next item in UpdateBlock
		}
	}

	/**
	 * A row of the queue may ask for more than STEPPER_COUNT instances of its code per step, through
	 * PARAM_STEP_COUNT of its own PARAMS: the constant is shared by every code ever queued, past and
	 * future, including repo blocks, while one migration may know its rows are cheap enough, or its
	 * codes urgent enough, to move faster.
	 * @param mixed $params PARAMS of the row of the queue.
	 * @return int
	 */
	private static function resolveStepCount(mixed $params): int
	{
		$stepCount = is_array($params) ? ($params[self::PARAM_STEP_COUNT] ?? null) : null;
		if (!is_int($stepCount) || $stepCount <= 0)
		{
			return static::STEPPER_COUNT;
		}

		return min($stepCount, self::STEP_COUNT_MAX);
	}

	protected static function updateVideoToLazyload($nodes)
	{
		foreach($nodes as $key => $node)
		{
			if($node['src'])
			{
				// youtube
				if (strpos($node['src'], 'www.youtube.com') !== false)
				{
					if ($node['source'])
					{
						$pattern = "#(youtube\\.com|youtu\\.be|youtube\\-nocookie\\.com)\\/(watch\\?(.*&)?v=|v\\/|u\\/|embed\\/?)?(videoseries\\?list=(.*)|[\\w-]{11}|\\?listType=(.*)&list=(.*))(.*)#";
						if(preg_match($pattern, $node['source'], $matches))
						{
							$nodes[$key]['preview'] = "//img.youtube.com/vi/{$matches[4]}/sddefault.jpg";
						}
					}
				}
			}
		}

		return $nodes;
	}
}