#!/bin/sh

USE_ZEND_ALLOC=0 # Zend allocator is a bottleneck in our allocation intensive generated code. system malloc is generally better
export USE_ZEND_ALLOC

if [ -x $PHP_INTERPRETER ]; then
    PHP_INTERPRETER="`which php54f` -d memory_limit=1G"
fi

SCRIPT=$1
shift

TEMP=`mktemp`

$PHP_INTERPRETER cps.php "$PHP_INTERPRETER" main top-level-include <<EOF > $TEMP
<?php
require '$SCRIPT';
EOF

$PHP_INTERPRETER $TEMP "$@"

rm $TEMP
