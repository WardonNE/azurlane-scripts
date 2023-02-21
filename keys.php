<?php
$filepath = $argv[1];
echo implode(',', array_keys(json_decode(file_get_contents($filepath), true)));
