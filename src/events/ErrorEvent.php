<?php
/**
 * @author Yohann Bianchi<yohann.b@lahautesociete.com>
 * @date   2018-11-14 13:20
 */

namespace lhs\elasticsearch\events;

use yii\base\Event;

class ErrorEvent extends Event
{
    /** @var \Exception */
    public $exception;

    public function __construct(\Exception $exception, array $config = [])
    {
        parent::__construct($config);

        $this->exception = $exception;
    }
}
