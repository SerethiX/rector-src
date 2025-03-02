<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig
        ->ruleWithConfiguration(StringClassNameToClassConstantRector::class, [
            'Nette\*',
            'Error',
            'Exception',

            // keep '\\' prefix string on string '\Foo\Bar'
            StringClassNameToClassConstantRector::SHOULD_KEEP_PRE_SLASH => true
        ]);
};
