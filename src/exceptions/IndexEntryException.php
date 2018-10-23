<?php
/**
 * @author Yohann Bianchi<yohann.b@lahautesociete.com>
 * @date   2018-09-13 11:28
 */

namespace lhs\elasticsearch\exceptions;


use \Exception;
use Throwable;
use Craft;
use yii\helpers\VarDumper;

class IndexEntryException extends Exception
{
    public function __construct(string $message = '', int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        Craft::error($message, __METHOD__);
        Craft::error(VarDumper::dumpAsString($this));
    }
}
