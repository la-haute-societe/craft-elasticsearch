<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\exceptions;


use Craft;
use Exception;
use Throwable;
use yii\helpers\VarDumper;

class IndexElementException extends Exception
{
    public function __construct(string $message = '', int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        Craft::error($message, __METHOD__);
        Craft::error(VarDumper::dumpAsString($this));
    }
}
