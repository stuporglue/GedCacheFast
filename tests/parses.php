<?php
require_once('autoload.php');

$parser = new \GedCacheFast\Parser();
$parser->parse('family.ged');

$indi = $parser->getIndi('I0001');

foreach($indi->getName() as $name){
    print "Name is $name\n";
}

print "-----------\n";

foreach($parser->getAllIndi() as $k => $v){
    print "$k: " . implode(", ",$v->getName()) . "\n";
}

foreach($parser->getAllName() as $k => $v){
    print $v;
}
