<?php

namespace lhs\elasticsearch\exceptions;

use Craft;
use Throwable;
use yii\helpers\VarDumper;

class Exception extends \yii\base\Exception
{
    public function __construct(string $message = '', int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        Craft::error($message, __METHOD__);
        Craft::error(VarDumper::dumpAsString($this));
    }
}
