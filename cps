#!/bin/sh
if [ -x $PHP_INTERPRETER ]; then
    PHP_INTERPRETER=`which php54`
fi
$PHP_INTERPRETER cps.php $PHP_INTERPRETER main top-level-include <<EOF | $PHP_INTERPRETER
<?php
include '$1';
EOF