<?php
require_once('autoload.php');

$parser = new \GedCacheFast\Parser();
$parser->parse('family.ged');

print_r($parser);
