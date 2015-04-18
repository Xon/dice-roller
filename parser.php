<?php
include_once('DiceTokenizer.php');
include_once('DiceParser.php');

//$data = "sum 1 1d6+1 + 1d8  * 2 d 10;1d6*1;2H20;2L20;4I6;4U1;4D6E4;4D6R8;";
#$data = "sum 1 (1d6+1 + 1d8  * 2 d 10)";
//$data = '1d6;2H20;2L20;4I6;4U1;4N20;7D10z1;1d6e4 + 1d6e4 + 1;6d6r8; 4d6f4; 4D10M4 ; 4D10S4';
$data = ' 4D6X4 ';
// todo: do not allow the following to exploderizer things
// explode=1,2,3,4,5,6 1d6
echo $data."\n\n";

$parser = new DiceParser($data);
$output = $parser->Parse();

print var_export($output, true) . "\n";
//eval( $output );







