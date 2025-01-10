<?php

use Symfony\Component\ErrorHandler\ErrorHandler;

require_once __DIR__.'/../vendor/autoload.php';

// https://github.com/symfony/symfony/issues/53812
set_exception_handler([new ErrorHandler(), 'handleException']);
