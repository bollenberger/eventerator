#!/bin/sh
if [ -x $PHP_INTERPRETER ]; then
    PHP_INTERPRETER="`which php54` -d memory_limit=1G"
fi
$PHP_INTERPRETER cps.php "$PHP_INTERPRETER" main top-level-include <<EOF | $PHP_INTERPRETER
<?php
require '$1';
EOF