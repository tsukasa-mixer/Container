<?php

namespace Tsukasa\Container\Exceptions;

class CircularContainerException extends ContainerException
{
    public function __construct($message = '', $code = 0, \Exception $previous = null)
    {
        $message .= '. Circular dependencies can be solved with calling setter with loaded service attribute.';
        parent::__construct($message, $code, $previous);
    }
}